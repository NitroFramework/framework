<?php

namespace Nitro\Session\Handlers;

use SessionHandlerInterface;

/**
 * In-memory session handler.
 *
 * Stores session payloads in a process-local array — nothing is persisted.
 * Intended for tests (and stateless contexts) where you want a real Store
 * without touching the filesystem or PHP's native session machinery.
 */
class ArraySessionHandler implements SessionHandlerInterface
{
    /** @var array<string, array{data: string, time: int}> */
    private array $storage = [];

    public function __construct(private int $minutes = 120) {}

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
        if (!isset($this->storage[$id])) {
            return '';
        }

        $entry = $this->storage[$id];
        if ($entry['time'] + ($this->minutes * 60) < time()) {
            unset($this->storage[$id]);
            return '';
        }

        return $entry['data'];
    }

    public function write(string $id, string $data): bool
    {
        $this->storage[$id] = ['data' => $data, 'time' => time()];
        return true;
    }

    public function destroy(string $id): bool
    {
        unset($this->storage[$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $cutoff = time() - $max_lifetime;
        $removed = 0;
        foreach ($this->storage as $id => $entry) {
            if ($entry['time'] < $cutoff) {
                unset($this->storage[$id]);
                $removed++;
            }
        }
        return $removed;
    }
}
