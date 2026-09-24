<?php

namespace Nitro\Filesystem\Contracts;

use Nitro\Http\FileResponse;

/**
 * A storage disk. Paths are relative to the disk's root.
 *
 * Not the same thing as {@see \Nitro\Filesystem\Filesystem}, which reads the
 * local machine by real path. A disk's root may not be local at all, which is
 * why nothing here takes an absolute path and why a URL is something the disk
 * is asked for rather than composed by the caller.
 */
interface Filesystem
{
    /** Whether anything is at the path, file or directory. */
    public function exists(string $path): bool;

    public function missing(string $path): bool;

    /** Whether a file is at the path. A directory there is not a file. */
    public function fileExists(string $path): bool;

    public function fileMissing(string $path): bool;

    /** Whether a directory is at the path. */
    public function directoryExists(string $directory): bool;

    public function directoryMissing(string $directory): bool;

    /** File contents, or null when it doesn't exist. */
    public function get(string $path): ?string;

    /**
     * File contents decoded from JSON, or null when absent or not JSON.
     *
     * @return array<mixed>|null
     */
    public function json(string $path, int $flags = 0): ?array;

    /**
     * A read handle, or null when the file doesn't exist.
     *
     * For a file too large to hold in memory. The caller closes it.
     *
     * @return resource|null
     */
    public function readStream(string $path);

    /** Write contents (string or stream resource). $options: 'visibility' => 'public'|'private'. */
    public function put(string $path, mixed $contents, array $options = []): bool;

    /**
     * Write from a read handle without buffering the whole file.
     *
     * @param resource $resource
     */
    public function writeStream(string $path, mixed $resource, array $options = []): bool;

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

    /** Copy a file onto another disk, streamed rather than buffered. */
    public function copyToDisk(string $from, self $disk, ?string $to = null, array $options = []): bool;

    /** Copy a file onto another disk, then remove this one's copy. */
    public function moveToDisk(string $from, self $disk, ?string $to = null, array $options = []): bool;

    /** Size in bytes, or null when missing. */
    public function size(string $path): ?int;

    /** Last-modified unix timestamp, or null when missing. */
    public function lastModified(string $path): ?int;

    /** The file's media type, or null when it cannot be determined. */
    public function mimeType(string $path): ?string;

    /** A hash of the file's contents, or null when missing. */
    public function checksum(string $path, array $options = []): ?string;

    /** 'public' or 'private', or null when missing. */
    public function getVisibility(string $path): ?string;

    public function setVisibility(string $path, string $visibility): bool;

    /** @return array<int, string> Files in $directory (optionally recursive). */
    public function files(?string $directory = null, bool $recursive = false): array;

    /** @return array<int, string> All files under $directory, recursively. */
    public function allFiles(?string $directory = null): array;

    /** @return array<int, string> Subdirectories of $directory. */
    public function directories(?string $directory = null, bool $recursive = false): array;

    /** @return array<int, string> All directories under $directory, recursively. */
    public function allDirectories(?string $directory = null): array;

    public function makeDirectory(string $path): bool;

    public function deleteDirectory(string $directory): bool;

    /** Absolute filesystem path for a relative path. Empty when the disk is remote. */
    public function path(string $path = ''): string;

    /** Public URL for a path (throws if the disk has no url configured). */
    public function url(string $path): string;

    /** Whether this disk can hand out a URL that expires. */
    public function providesTemporaryUrls(): bool;

    /**
     * A URL granting time-limited access to one file.
     *
     * Throws on a disk that cannot sign, so ask {@see providesTemporaryUrls()}
     * rather than assuming.
     */
    public function temporaryUrl(string $path, int $seconds = 3600, array $options = []): string;

    /** A response that makes the browser save the file. */
    public function download(string $path, ?string $name = null, array $headers = []): FileResponse;

    /** A response that shows the file in the browser. */
    public function response(string $path, ?string $name = null, array $headers = []): FileResponse;

    /**
     * The configuration this disk was built from.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array;
}
