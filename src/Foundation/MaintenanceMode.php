<?php

namespace Nitro\Foundation;

/**
 * Take the application down for maintenance and bring it back, using a file on disk.
 */
class MaintenanceMode
{
    /**
     * @param string $file The file whose presence means the application is down.
     */
    public function __construct(
        protected string $file,
    ) {}

    /** Determine whether the application is down. */
    public function active(): bool
    {
        return is_file($this->file);
    }

    /**
     * Take the application down.
     *
     * @param array<string, mixed> $payload Retry seconds, a bypass secret, a message.
     */
    public function activate(array $payload = []): void
    {
        $directory = dirname($this->file);

        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        file_put_contents($this->file, json_encode($payload + ['time' => time()]));
    }

    /** Bring the application back up. */
    public function deactivate(): void
    {
        if ($this->active()) {
            @unlink($this->file);
        }
    }

    /**
     * Get what was recorded when the application went down.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        if (! $this->active()) {
            return [];
        }

        $contents = @file_get_contents($this->file);

        return is_string($contents) ? (json_decode($contents, true) ?: []) : [];
    }

    /** Get the seconds for the Retry-After header, when one was given. */
    public function retryAfter(): ?int
    {
        $retry = $this->data()['retry'] ?? null;

        return $retry === null ? null : (int) $retry;
    }

    /** Determine whether the given secret lets a visitor through while the application is down. */
    public function bypassedBy(?string $secret): bool
    {
        $expected = $this->data()['secret'] ?? null;

        return is_string($expected)
            && $expected !== ''
            && is_string($secret)
            && hash_equals($expected, $secret);
    }
}
