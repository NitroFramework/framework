<?php

namespace Nitro\Foundation\Providers;

use Nitro\Thrust\WorkerMode;
use Nitro\Http\Kernel;
use Nitro\Http\Middleware\StartSession;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Session\Contracts\SessionInterface;
use Nitro\Session\NativeSession;
use Nitro\Session\SessionManager;
use Nitro\Session\Store;

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
            return new SessionManager($config);
        });

        // The kernel resolves route middleware fresh on every request. Bind
        // StartSession explicitly so that is a cache hit rather than a
        // reflection-driven autowire on each one — it holds nothing but the
        // container, so a shared instance is safe in a long-running worker.
        // (Measured: autowiring it cost ~8% of throughput on 'web' routes.)
        $this->container->singleton(StartSession::class, fn($container) => new StartSession($container));

        // Scoped: one Store per worker request; the binding declares its own
        // lifecycle rather than relying on a central reset list.
        $this->container->scoped('session', fn($container) => $container->createOrResolve(SessionManager::class)->driver());
        $this->container->alias(SessionInterface::class, 'session');
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

        $path = (string) ($config['files'] ?? $this->container->createOrResolve('paths')->storage('framework/sessions'));

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
     * here. It lives in {@see \Nitro\Http\Middleware\StartSession}, a member of
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
        $kernel = $this->container->createOrResolve(Kernel::class);

        // Emit the session cookie BEFORE the response is sent so the browser
        // returns the id next request — without this, file/array sessions minted
        // a fresh id every request and never persisted. responseReady runs
        // pre-send (and on the error path too).
        $kernel->responseReady(function (Request $request, Response $response): void {
            $session = $this->container->createOrResolve('session');

            if ($session->isStarted() && ! $session instanceof NativeSession) {
                $response->header(
                    'Set-Cookie',
                    $this->sessionCookieHeader($session->getName(), $session->getId(), $request)
                );
            }
        });

        $kernel->terminating(function (Request $request, Response $response): void {
            $session = $this->container->createOrResolve('session');

            // Untouched by StartSession => this route has no session; nothing
            // to flush and nothing to sweep.
            if (! $session->isStarted()) {
                return;
            }

            // save() flushes and releases the native lock.
            $session->save();
        });
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
