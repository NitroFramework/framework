<?php

namespace Nitro\Session;

use Nitro\Cookie\CookieJar;
use Nitro\Http\Request;
use SessionHandlerInterface;

/**
 * Stores the session payload in the session cookie itself.
 *
 * Nothing is written server-side, so the store needs no shared filesystem,
 * database or Redis. The payload travels on every request, which bounds what
 * the session can hold, and it is only as private as the cookie: run it behind
 * the cookie encryption middleware.
 */
class CookieSessionHandler implements SessionHandlerInterface
{
    protected ?Request $request = null;

    /**
     * @param int  $minutes       How long the payload stays valid.
     * @param bool $expireOnClose Issue a session cookie rather than a dated one.
     */
    public function __construct(
        protected CookieJar $cookie,
        protected int $minutes,
        protected bool $expireOnClose = false,
    ) {}

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $value = $this->request?->cookie($id) ?: '';

        if (! is_string($value) || $value === '') {
            return '';
        }

        $decoded = json_decode($value, true);

        if (is_array($decoded) && isset($decoded['expires']) && time() <= $decoded['expires']) {
            return (string) $decoded['data'];
        }

        return '';
    }

    public function write(string $id, string $data): bool
    {
        $this->cookie->queue($id, json_encode([
            'data' => $data,
            'expires' => time() + ($this->minutes * 60),
        ]), $this->expireOnClose ? 0 : $this->minutes);

        return true;
    }

    public function destroy(string $id): bool
    {
        $this->cookie->queue($this->cookie->forget($id));

        return true;
    }

    public function gc(int $max_lifetime): int
    {
        return 0;
    }

    /**
     * Set the request the payload is read from.
     */
    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }
}
