<?php

namespace Nitro\Cookie;

use Nitro\Http\Cookie;

/**
 * Builds cookies from the configured defaults and holds a queue of cookies to
 * attach to the outgoing response. Defaults (path/domain/secure/same_site) come
 * from config('session') so app cookies share the session's scope.
 */
class CookieJar
{
    /** @var array<string, Cookie> Queued cookies, keyed by "name;path". */
    protected array $queued = [];

    public function __construct(
        protected string $path = '/',
        protected ?string $domain = null,
        protected bool $secure = false,
        protected string $sameSite = 'lax',
    ) {}

    /** A cookie that expires in $minutes (0 = a session cookie). */
    public function make(
        string $name,
        string $value,
        int $minutes = 0,
        ?string $path = null,
        ?string $domain = null,
        ?bool $secure = null,
        bool $httpOnly = true,
        bool $raw = false,
        ?string $sameSite = null,
    ): Cookie {
        return new Cookie(
            $name,
            $value,
            $minutes === 0 ? 0 : time() + $minutes * 60,
            $path ?? $this->path,
            $domain ?? $this->domain,
            $secure ?? $this->secure,
            $httpOnly,
            $sameSite ?? $this->sameSite,
            $raw,
        );
    }

    /** A cookie that lasts ~5 years. */
    public function forever(
        string $name,
        string $value,
        ?string $path = null,
        ?string $domain = null,
        ?bool $secure = null,
        bool $httpOnly = true,
        bool $raw = false,
        ?string $sameSite = null,
    ): Cookie {
        return $this->make($name, $value, 2628000, $path, $domain, $secure, $httpOnly, $raw, $sameSite);
    }

    /** A cookie that deletes the client value. */
    public function forget(string $name, ?string $path = null, ?string $domain = null): Cookie
    {
        return new Cookie($name, '', time() - 3600, $path ?? $this->path, $domain ?? $this->domain, $this->secure);
    }

    /** Queue a cookie (a Cookie instance, or make() arguments). */
    public function queue(Cookie|string $cookie, mixed ...$args): void
    {
        if (! $cookie instanceof Cookie) {
            $cookie = $this->make($cookie, ...$args);
        }

        $this->queued[$this->key($cookie->name, $cookie->path)] = $cookie;
    }

    /** Remove a cookie from the queue. */
    public function unqueue(string $name, ?string $path = null): void
    {
        if ($path === null) {
            foreach (array_keys($this->queued) as $k) {
                if (str_starts_with($k, $name . ';')) {
                    unset($this->queued[$k]);
                }
            }

            return;
        }

        unset($this->queued[$this->key($name, $path)]);
    }

    public function hasQueued(string $name, ?string $path = null): bool
    {
        return $this->queued($name, null, $path) !== null;
    }

    public function queued(string $name, mixed $default = null, ?string $path = null): ?Cookie
    {
        foreach ($this->queued as $k => $cookie) {
            if ($path !== null ? $k === $this->key($name, $path) : str_starts_with($k, $name . ';')) {
                return $cookie;
            }
        }

        return $default;
    }

    /** @return array<int, Cookie> */
    public function getQueuedCookies(): array
    {
        return array_values($this->queued);
    }

    private function key(string $name, string $path): string
    {
        return $name . ';' . $path;
    }
}
