<?php

namespace Nitro\Livewire;

/**
 * A file that the client has uploaded ahead of the action that consumes it. It
 * lives in storage/app/livewire-tmp until an action calls store()/storeAs() to
 * move it into permanent storage. Instances are referenced across requests by
 * an opaque "livewire-file:<name>" token; the original client filename, size
 * and mime type travel in the snapshot (or a sidecar written at upload time).
 */
class TemporaryUploadedFile
{
    /** Token prefix that marks a wire:model value as a pending upload. */
    public const PREFIX = 'livewire-file:';

    /** Directory (relative to storage/app) that holds pending uploads. */
    public const TMP_DIR = 'livewire-tmp';

    public function __construct(
        protected string $filename,
        protected array $meta = [],
    ) {
        // Never trust a caller-supplied name with path separators.
        $this->filename = basename($this->filename);

        if ($this->meta === [] && is_file($this->sidecarPath())) {
            $this->meta = json_decode((string) file_get_contents($this->sidecarPath()), true) ?: [];
        }
    }

    // ─── Reference tokens (client ⇄ server) ─────────────────────────────────

    /** Whether a wire:model value is an upload token (a string or an array of them). */
    public static function isUploadToken(mixed $value): bool
    {
        if (is_string($value)) {
            return str_starts_with($value, self::PREFIX);
        }

        if (is_array($value) && $value !== []) {
            foreach ($value as $item) {
                if (! is_string($item) || ! str_starts_with($item, self::PREFIX)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /** Turn an upload token (or array of tokens) into file instance(s). */
    public static function fromValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(static fn($t) => self::fromToken($t), $value);
        }

        return self::fromToken((string) $value);
    }

    /** Build an instance from a single "livewire-file:<name>" token. */
    public static function fromToken(string $token): self
    {
        return new self(substr($token, strlen(self::PREFIX)));
    }

    /** The token that represents this file in a wire:model value / snapshot payload. */
    public function toReference(): string
    {
        return self::PREFIX . $this->filename;
    }

    // ─── Client metadata ────────────────────────────────────────────────────

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getClientOriginalName(): string
    {
        return $this->meta['name'] ?? $this->filename;
    }

    public function getClientOriginalExtension(): string
    {
        return pathinfo($this->getClientOriginalName(), PATHINFO_EXTENSION);
    }

    /**
     * The REAL mime type, read from the file's content — never the client's
     * claimed type. Validate against this; a `.php` renamed to `.png` can't lie
     * its way past a getMimeType() check. Use getClientMimeType() only when you
     * explicitly want the (untrusted) client claim.
     */
    public function getMimeType(): ?string
    {
        return is_file($this->getRealPath())
            ? (mime_content_type($this->getRealPath()) ?: null)
            : null;
    }

    /** The mime type the client CLAIMED — untrusted; do not use for validation. */
    public function getClientMimeType(): ?string
    {
        return $this->meta['type'] ?? null;
    }

    /** The REAL size on disk (not the client-claimed size). */
    public function getSize(): int
    {
        return is_file($this->getRealPath()) ? (int) filesize($this->getRealPath()) : 0;
    }

    /** Metadata carried in the snapshot so the file survives round-trips. */
    public function meta(): array
    {
        return $this->meta;
    }

    // ─── Filesystem ─────────────────────────────────────────────────────────

    /** Absolute path to the temporary file. */
    public function getRealPath(): string
    {
        return storage_path('app/' . self::TMP_DIR . '/' . $this->filename);
    }

    /** Whether the temporary file still exists on disk. */
    public function isValid(): bool
    {
        return is_file($this->getRealPath());
    }

    /**
     * Move the file into permanent storage under storage/app/<directory> with a
     * generated name; returns the stored path relative to storage/app.
     */
    public function store(string $directory, ?string $disk = null): string
    {
        $name = bin2hex(random_bytes(16)) . '.' . $this->getClientOriginalExtension();

        return $this->storeAs($directory, $name, $disk);
    }

    /**
     * Move the file into permanent storage under storage/app/<directory> with an
     * explicit name; returns the stored path relative to storage/app.
     */
    public function storeAs(string $directory, string $name, ?string $disk = null): string
    {
        // Strip traversal/absolute segments so the target can never escape
        // storage/app, even if $directory carries caller-tainted input.
        $directory = $this->sanitizeDirectory($directory);
        $target = storage_path('app/' . $directory);

        if (! is_dir($target)) {
            mkdir($target, 0775, true);
        }

        $relative = ($directory === '' ? '' : $directory . '/') . basename($name);
        rename($this->getRealPath(), storage_path('app/' . $relative));
        @unlink($this->sidecarPath());

        return $relative;
    }

    /** Delete the temporary file and its sidecar (e.g. on validation failure). */
    public function delete(): void
    {
        @unlink($this->getRealPath());
        @unlink($this->sidecarPath());
    }

    /** Path to the JSON sidecar written at upload time (original name/size/type). */
    protected function sidecarPath(): string
    {
        return $this->getRealPath() . '.meta.json';
    }

    /**
     * Collapse a target directory to safe segments — no '', '.', '..', or
     * drive/absolute prefixes — so a stored file can't land outside storage/app.
     */
    protected function sanitizeDirectory(string $directory): string
    {
        $segments = [];
        foreach (preg_split('#[\\\\/]+#', $directory) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, ':')) {
                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }
}
