<?php

namespace Nitro\Session;

use Nitro\Cache\Repository;
use Nitro\Cookie\CookieJar;
use Nitro\Encryption\Contracts\Encrypter;
use Nitro\Foundation\Providers\ServiceProvider;
use Nitro\Http\Kernel;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Session\Contracts\Session;
use Nitro\Session\Middleware\StartSession;
use Nitro\Support\Logger;
use Nitro\Thrust\WorkerMode;

/**
 * Wires the session layer into the container.
 *
 * The active Store is bound as a SCOPED service: in worker mode it is rebuilt
 * per request (via the container's forgetScopedInstances()), so each request
 * gets that user's session and state never leaks across requests — without any
 * entry in WorkerMode's reset list.
 *
 * NOTE: the request-lifecycle wiring (read the session id from the cookie,
 * start() at request begin, save() + set-cookie at response end) is installed
 * by the HTTP kernel as a separate, browser-verified step; this provider just
 * makes the layer resolvable.
 */
class SessionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(SessionManager::class, function ($container) {
            $config = (array) config('session');
            $config['driver']   ??= 'native';

            // The native driver relies on ext/session process globals that
            // FrankenPHP does not tear down between worker iterations — a slow
            // per-request memory leak (and a cross-request state hazard). Under
            // Thrust/worker mode, transparently use the worker-safe file store,
            // which mints a fresh Store per request and never touches
            // session_start(). Non-worker (FPM/serve) keeps native as-is.
            if ($config['driver'] === 'native' && $container->has(WorkerMode::class)) {
                $config['driver'] = 'file';
            }

            $config['cookie']   ??= 'nitro_session';
            $config['lifetime'] ??= 120;
            $config['files']    ??= $container->get('paths')->storage('framework/sessions');
            // Resolved lazily: a driver only reaches for its backing layer when
            // it is the one selected, and that layer may not be registered.
            $redis = fn (?string $connection): object => $container->get('redis')->connection($connection);
            $cookie = fn (): CookieJar => $container->get('cookie');
            $cache = fn (string $store): Repository => $container->get('cache')->driver($store);
            $encrypter = fn (): Encrypter => $container->get('encrypter');

            return new SessionManager(
                $config,
                $container->has('redis') ? $redis : null,
                $container->has('cookie') ? $cookie : null,
                $container->has('cache') ? $cache : null,
                $container->has('encrypter') ? $encrypter : null,
            );
        });

        // The kernel resolves route middleware fresh on every request. Bind
        // StartSession explicitly so that is a cache hit rather than a
        // reflection-driven autowire on each one — it holds nothing but the
        // container, so a shared instance is safe in a long-running worker.
        // (Measured: autowiring it cost ~8% of throughput on 'web' routes.)
        $this->container->singleton(StartSession::class, fn($container) => new StartSession($container));

        // Scoped: one Store per worker request; the binding declares its own
        // lifecycle rather than relying on a central reset list.
        $this->container->scoped('session', fn($container) => $container->resolve(SessionManager::class)->driver());
        $this->container->alias(Session::class, 'session');
        $this->container->alias(Store::class, 'session');

        $this->configureNativeSessionPath();
    }

    /**
     * Point PHP's native session storage at the configured directory. The native
     * driver relies on PHP's own session_start(), so session_save_path must be set
     * before any session begins — the front controller no longer does this.
     */
    protected function configureNativeSessionPath(): void
    {
        // save_path can only be set before a session starts; skip if one's active.
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $config = (array) config('session');

        if (($config['driver'] ?? 'native') !== 'native') {
            return;
        }

        $path = (string) ($config['files'] ?? $this->container->resolve('paths')->storage('framework/sessions'));

        if (! is_dir($path)) {
            @mkdir($path, 0755, true);
        }
        if (is_dir($path) && is_writable($path)) {
            session_save_path($path);
        }
    }

    /**
     * Install the closing half of the request-lifecycle wiring on the HTTP
     * kernel: emit the session cookie before the response is sent, and save the
     * session after it has been.
     *
     * The opening half — seeding the id from the cookie and start() — is NOT
     * here. It lives in {@see \Nitro\Session\Middleware\StartSession}, a member of
     * the 'web' middleware group, so only routes that actually want a session
     * build one. A global requestReceived hook could never do that: it fires in
     * Kernel::handle() one line *before* the router matches, so there is no
     * route (and therefore no middleware group) to consult yet, and every
     * request — stateless JSON included — paid for a session file read + write.
     *
     * These two stay hooks rather than moving into that middleware because both
     * must survive an exception unwinding the middleware stack: a validation
     * failure throws HttpResponseException to short-circuit with errors flashed
     * to the session, and a post-$next block in the middleware would be skipped,
     * losing them. terminating() also runs after Response::send(), keeping the
     * write off the critical path. Both no-op unless StartSession ran.
     */
    public function boot(): void
    {
        $kernel = $this->container->resolve(Kernel::class);

        // Emit the session cookie BEFORE the response is sent so the browser
        // returns the id next request — without this, file/array sessions minted
        // a fresh id every request and never persisted. responseReady runs
        // pre-send (and on the error path too).
        $kernel->responseReady(function (Request $request, Response $response): void {
            $session = $this->container->resolve('session');

            if ($session->isStarted() && ! $session instanceof NativeSession) {
                $response->header(
                    'Set-Cookie',
                    $this->sessionCookieHeader($session->getName(), $session->getId(), $request)
                );
            }
        });

        $kernel->terminating(function (Request $request, Response $response): void {
            $session = $this->container->resolve('session');

            // Untouched by StartSession => this route has no session; nothing
            // to flush and nothing to sweep.
            if (! $session->isStarted()) {
                return;
            }

            // save() flushes and releases the native lock.
            $session->save();

            $this->sweepExpiredSessions($session);
        });
    }

    /**
     * Occasionally delete sessions nobody came back for.
     *
     * An application should not have to be told to clean up after itself, so
     * this runs on a lottery rather than from a command someone has to
     * remember to schedule — a file driver otherwise grows one dead payload for
     * every client that never returns its cookie, which is every health check,
     * crawler and load generator that ever touched it.
     *
     * Two things keep it off the critical path. It runs from the terminating
     * hook, after the response has been sent, so no client waits for it. And it
     * removes at most a fixed number of files per sweep, so the cost does not
     * grow with the size of the backlog — an unbounded walk would stall the
     * worker, and every request queued behind it, for as long as the directory
     * took to read.
     */
    protected function sweepExpiredSessions(Session $session): void
    {
        $config = (array) config('session');
        [$chances, $outOf] = $config['lottery'] ?? [2, 100];

        if ($chances < 1 || $outOf < 1 || random_int(1, $outOf) > $chances) {
            return;
        }

        try {
            $session->collectGarbage(
                (int) ($config['lifetime'] ?? 120),
                (int) ($config['sweep_limit'] ?? 100)
            );
        } catch (\Throwable $exception) {
            // Housekeeping must never turn a served response into an error.
            Logger::debug('Session sweep failed', ['exception' => $exception->getMessage()]);
        }
    }

    /**
     * Build the Set-Cookie header value for a self-managed (file/array) session,
     * using the configured cookie attributes. `secure` defaults to "auto" — set
     * only over HTTPS.
     */
    private function sessionCookieHeader(string $name, string $id, Request $request): string
    {
        $config   = (array) config('session');
        $lifetime = (int) ($config['lifetime'] ?? 120); // minutes
        $secure   = $config['secure'] ?? $request->secure();
        $sameSite = ucfirst((string) ($config['same_site'] ?? 'lax'));

        $parts = [
            rawurlencode($name) . '=' . rawurlencode($id),
            'Path=' . ($config['path'] ?? '/'),
            'Max-Age=' . ($lifetime * 60),
            'SameSite=' . $sameSite,
        ];

        if (($config['http_only'] ?? true)) {
            $parts[] = 'HttpOnly';
        }
        if (!empty($config['domain'])) {
            $parts[] = 'Domain=' . $config['domain'];
        }
        if ($secure) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }
}
