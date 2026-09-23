<?php

namespace Nitro\Auth\Middleware;

use Nitro\Auth\AuthManager;
use Nitro\Auth\Contracts\StatefulGuard;
use Nitro\Http\Request;
use Nitro\Http\Response;

/**
 * Authenticates by HTTP basic auth.
 *
 *     Route::get('/metrics', ...)->middleware('auth.basic');
 *
 * For something with no sign-in page of its own — an internal endpoint,
 * a scrape target. The credentials travel on every request, so this
 * belongs behind TLS and nowhere else.
 */
class AuthenticateWithBasicAuth
{
    public function __construct(protected AuthManager $auth) {}

    /**
     * @param ?string $guard The guard to check against.
     * @param ?string $field The column the username is matched on.
     */
    public function handle(Request $request, callable $next, ?string $guard = null, ?string $field = null): Response
    {
        $credentials = $this->credentialsFrom($request, $field ?? 'email');

        $target = $this->auth->guard($guard);

        // once(), not attempt(): basic auth arrives with every request, so
        // there is nothing for a session to add.
        $authenticated = $credentials !== null
            && $target instanceof StatefulGuard
            && $target->once($credentials);

        if (! $authenticated) {
            return $this->challenge();
        }

        return $next($request);
    }

    /**
     * The username and password the header carries, if it carries any.
     *
     * @return array<string, string>|null
     */
    protected function credentialsFrom(Request $request, string $field): ?array
    {
        $header = $request->header('authorization');

        if (! is_string($header) || stripos($header, 'basic ') !== 0) {
            return null;
        }

        $decoded = base64_decode(substr($header, 6), true);

        if ($decoded === false || ! str_contains($decoded, ':')) {
            return null;
        }

        [$username, $password] = explode(':', $decoded, 2);

        return [$field => $username, 'password' => $password];
    }

    /**
     * Ask the browser for credentials.
     *
     * The WWW-Authenticate header is what makes a browser show its own
     * prompt; without it a 401 is just an error page.
     */
    protected function challenge(): Response
    {
        return (new Response('Invalid credentials.', 401))
            ->header('WWW-Authenticate', 'Basic realm="Restricted"');
    }
}
