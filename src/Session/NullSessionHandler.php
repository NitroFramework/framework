<?php

namespace Nitro\Session;

use SessionHandlerInterface;

/**
 * Discards everything written to it and reads back nothing.
 *
 * Useful for routes that must not carry state, and for tests that want the
 * session API present without a backing store.
 */
class NullSessionHandler implements SessionHandlerInterface
{
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
        return '';
    }

    public function write(string $id, string $data): bool
    {
        return true;
    }

    public function destroy(string $id): bool
    {
        return true;
    }

    public function gc(int $max_lifetime): int
    {
        return 0;
    }
}
