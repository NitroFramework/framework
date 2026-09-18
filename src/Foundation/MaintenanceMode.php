<?php

namespace Nitro\Foundation;

/**
 * Whether the application is down for maintenance.
 *
 *     MaintenanceMode::activate(['retry' => 60, 'secret' => 'let-me-in']);
 *     MaintenanceMode::active();
 *     MaintenanceMode::deactivate();
 *
 * Held in a file rather than in the cache or a database, because maintenance
 * mode has to work when those are the thing being worked on.
 */
class MaintenanceMode
{
    public function __construct(
        protected string $file,
    ) {}

    /** Whether the application is currently down. */
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
     * What was recorded when the application went down.
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

    /** Seconds to put in the Retry-After header, when one was given. */
    public function retryAfter(): ?int
    {
        $retry = $this->data()['retry'] ?? null;

        return $retry === null ? null : (int) $retry;
    }

    /** Whether this secret lets somebody through while the app is down. */
    public function bypassedBy(?string $secret): bool
    {
        $expected = $this->data()['secret'] ?? null;

        return is_string($expected)
            && $expected !== ''
            && is_string($secret)
            && hash_equals($expected, $secret);
    }
}
