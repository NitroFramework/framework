<?php

namespace Nitro\Thrust\FrankenPhp;

use RuntimeException;

/**
 * Persists the running server's process id and configuration (host, port,
 * admin endpoint, worker count) to a small JSON file under storage/, so that
 * thrust:stop / thrust:status / thrust:reload can find and talk to a server
 * started by a separate thrust:start invocation.
 */
class ServerStateFile
{
    public function __construct(private string $path) {}

    /** Read the persisted state, normalised to a predictable shape. */
    public function read(): array
    {
        $state = is_readable($this->path)
            ? json_decode((string) file_get_contents($this->path), true)
            : [];

        return [
            'masterProcessId' => $state['masterProcessId'] ?? null,
            'state' => $state['state'] ?? [],
        ];
    }

    /** Record the master process id alongside any existing state. */
    public function writeProcessId(int $masterProcessId): void
    {
        $this->write(['masterProcessId' => $masterProcessId] + $this->read());
    }

    /** Record the server configuration (merged over any existing state). */
    public function writeState(array $newState): void
    {
        $this->write(array_merge($this->read(), ['state' => $newState]));
    }

    /** Remove the state file (called on stop). */
    public function delete(): bool
    {
        return is_file($this->path) && unlink($this->path);
    }

    public function path(): string
    {
        return $this->path;
    }

    private function write(array $data): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Unable to create server-state directory: {$dir}");
        }

        file_put_contents($this->path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
