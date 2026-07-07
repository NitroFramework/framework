<?php

namespace Nitro\Filesystem\Contracts;

/**
 * A storage disk. Paths are relative to the disk's root. Implemented by
 * LocalFilesystem today; the same surface fits a cloud driver later.
 */
interface Filesystem
{
    public function exists(string $path): bool;

    public function missing(string $path): bool;

    /** File contents, or null when it doesn't exist. */
    public function get(string $path): ?string;

    /** Write contents (string or stream resource). $options: 'visibility' => 'public'|'private'. */
    public function put(string $path, mixed $contents, array $options = []): bool;

    /** Store a file under a generated random name in $directory; returns the stored path. */
    public function putFile(string $directory, string $sourcePath, array $options = []): string;

    /** Store a file under $name in $directory; returns the stored path. */
    public function putFileAs(string $directory, string $sourcePath, string $name, array $options = []): string;

    public function prepend(string $path, string $data): bool;

    public function append(string $path, string $data): bool;

    /** Delete one or more files. */
    public function delete(string|array $paths): bool;

    public function copy(string $from, string $to): bool;

    public function move(string $from, string $to): bool;

    /** Size in bytes, or null when missing. */
    public function size(string $path): ?int;

    /** Last-modified unix timestamp, or null when missing. */
    public function lastModified(string $path): ?int;

    /** @return array<int, string> Files in $directory (optionally recursive). */
    public function files(?string $directory = null, bool $recursive = false): array;

    /** @return array<int, string> All files under $directory, recursively. */
    public function allFiles(?string $directory = null): array;

    /** @return array<int, string> Subdirectories of $directory. */
    public function directories(?string $directory = null, bool $recursive = false): array;

    public function makeDirectory(string $path): bool;

    public function deleteDirectory(string $directory): bool;

    /** Absolute filesystem path for a relative path. */
    public function path(string $path = ''): string;

    /** Public URL for a path (throws if the disk has no url configured). */
    public function url(string $path): string;
}
