<?php

namespace Nitro\Http\Middleware;

use Nitro\Cache\RateLimiter;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Request;
use Nitro\Http\Response;

/**
 * Rate-limits requests per (IP + path), returning 429 with a Retry-After header
 * once the window's cap is exceeded. Limits come from config `throttle.*`
 * (Nitro's middleware aliases don't take `:params`, so it's config-driven).
 *
 * For the more targeted login lockout, controllers use the RateLimiter directly.
 */
class ThrottleRequests
{
    public function __construct(
        private RateLimiter $limiter,
        private ConfigRepository $config,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $max   = (int) ($this->config->get('throttle.max_attempts') ?? 60);
        $decay = (int) ($this->config->get('throttle.decay') ?? 60);
        $key   = $this->resolveKey($request);

        if ($this->limiter->tooManyAttempts($key, $max)) {
            return new Response('Too Many Requests', 429, [
                'Retry-After'           => (string) $this->limiter->availableIn($key),
                'X-RateLimit-Limit'     => (string) $max,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        $this->limiter->hit($key, $decay);

        $response = $next($request);
        $response->header('X-RateLimit-Limit', (string) $max);
        $response->header('X-RateLimit-Remaining', (string) $this->limiter->remaining($key, $max));

        return $response;
    }

    /** Key by IP + path (an authenticated-user key can be layered on later). */
    private function resolveKey(Request $request): string
    {
        return ($request->ip() ?? 'unknown') . '|' . $request->method() . '|' . $request->path();
    }
}
