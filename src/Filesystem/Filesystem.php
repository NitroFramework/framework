<?php

namespace Nitro\Filesystem;

use FilesystemIterator;
use Generator;
use Nitro\Filesystem\Exceptions\FileNotFoundException;
use Nitro\Support\Conditionable;
use Nitro\Support\Macroable;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use SplFileObject;

/**
 * The local filesystem, addressed by real paths.
 *
 *     File::get(base_path('composer.json'));
 *     File::ensureDirectoryExists(storage_path('app/exports'));
 *
 * Distinct from a storage disk, and deliberately so. A disk has a root and
 * every path is relative to it, which is what you want for user uploads that
 * may later live in a bucket. This is for the paths the application already
 * knows in full — a config file, a compiled view, a directory a command is
 * about to write into — where going through a disk means naming a disk that
 * has nothing to do with the question.
 *
 * {@see FilesystemManager} is the other one.
 */
class Filesystem
{
    use Conditionable;
    use Macroable;

    /**
     * Enough of a mime table to answer guessExtension().
     *
     * Only the reverse direction needs a table: mimeType() reads the file
     * itself. Anything absent here is unanswerable rather than guessed wrong.
     *
     * @var array<string, string>
     */
    private const EXTENSIONS = [
        'application/json' => 'json',
        'application/pdf' => 'pdf',
        'application/zip' => 'zip',
        'application/gzip' => 'gz',
        'application/xml' => 'xml',
        'application/javascript' => 'js',
        'application/octet-stream' => 'bin',
        'image/gif' => 'gif',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/svg+xml' => 'svg',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/bmp' => 'bmp',
        'image/x-icon' => 'ico',
        'text/csv' => 'csv',
        'text/css' => 'css',
        'text/html' => 'html',
        'text/plain' => 'txt',
        'text/xml' => 'xml',
        'font/woff' => 'woff',
        'font/woff2' => 'woff2',
        'audio/mpeg' => 'mp3',
        'video/mp4' => 'mp4',
    ];

    // ─── Reading ────────────────────────────────────────────

    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    public function missing(string $path): bool
    {
        return ! $this->exists($path);
    }

    /**
     * The contents of a file.
     *
     * @param bool $lock Whether to take a shared lock while reading, for a
     *                   file another process may be rewriting.
     *
     * @throws FileNotFoundException
     */
    public function get(string $path, bool $lock = false): string
    {
        if (! $this->isFile($path)) {
            throw FileNotFoundException::at($path);
        }

        return $lock ? $this->sharedGet($path) : (string) file_get_contents($path);
    }

    /**
     * The contents of a file, decoded from JSON.
     *
     * @return array<mixed>
     *
     * @throws FileNotFoundException
     */
    public function json(string $path, int $flags = 0, bool $lock = false): array
    {
        return (array) json_decode($this->get($path, $lock), true, 512, $flags);
    }

    /**
     * The contents of a file, read under a shared lock.
     *
     * The stat cache is cleared inside the lock, because a size read before
     * the lock was taken describes the file as it was being written.
     */
    public function sharedGet(string $path): string
    {
        $contents = '';

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return $contents;
        }

        try {
            if (flock($handle, LOCK_SH)) {
                clearstatcache(true, $path);

                $contents = (string) stream_get_contents($handle);

                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }

        return $contents;
    }

    /**
     * What a PHP file returns.
     *
     * @param array<string, mixed> $data Variables made available to the file.
     *
     * @throws FileNotFoundException
     */
    public function getRequire(string $path, array $data = []): mixed
    {
        if (! $this->isFile($path)) {
            throw FileNotFoundException::at($path);
        }

        // A static closure, so the file cannot reach $this or any local of
        // this method beyond what was passed in.
        return (static function () use ($path, $data) {
            extract($data, EXTR_SKIP);

            return require $path;
        })();
    }

    /**
     * What a PHP file returns, requiring it at most once.
     *
     * @param array<string, mixed> $data
     *
     * @throws FileNotFoundException
     */
    public function requireOnce(string $path, array $data = []): mixed
    {
        if (! $this->isFile($path)) {
            throw FileNotFoundException::at($path);
        }

        return (static function () use ($path, $data) {
            extract($data, EXTR_SKIP);

            return require_once $path;
        })();
    }

    /**
     * The file's lines, one at a time.
     *
     * Lazy, so a log file larger than memory can still be walked.
     *
     * @return Generator<int, string>
     *
     * @throws FileNotFoundException
     */
    public function lines(string $path): Generator
    {
        if (! $this->isFile($path)) {
            throw FileNotFoundException::at($path);
        }

        $file = new SplFileObject($path);

        $file->setFlags(SplFileObject::DROP_NEW_LINE);

        while (! $file->eof()) {
            yield (string) $file->fgets();
        }
    }

    /** The file's hash, or false when it cannot be read. */
    public function hash(string $path, string $algorithm = 'md5'): string|false
    {
        return hash_file($algorithm, $path);
    }

    /**
     * Whether two files hold the same bytes.
     *
     * Compared by hash rather than by reading both into memory, and with
     * hash_equals so the comparison does not leak where they diverge.
     */
    public function hasSameHash(string $first, string $second): bool
    {
        $hash = @hash_file('xxh128', $first);

        return $hash !== false && hash_equals($hash, (string) @hash_file('xxh128', $second));
    }

    // ─── Writing ────────────────────────────────────────────

    /**
     * Write a file, returning the bytes written.
     *
     * @param bool $lock Whether to hold an exclusive lock for the write.
     */
    public function put(string $path, string $contents, bool $lock = false): int|false
    {
        return file_put_contents($path, $contents, $lock ? LOCK_EX : 0);
    }

    /**
     * Write a file so a reader never sees it half-written.
     *
     * The contents go to a temporary file in the same directory and are then
     * renamed over the target, which is atomic within one filesystem. A reader
     * opening the path sees either the whole old file or the whole new one.
     */
    public function replace(string $path, string $contents, ?int $mode = null): void
    {
        // A symlink must be followed, or the rename replaces the link itself.
        clearstatcache(true, $path);

        $path = realpath($path) ?: $path;

        $temporary = (string) tempnam(dirname($path), basename($path));

        // tempnam() creates it 0600, which is rarely what the target should be.
        @chmod($temporary, $mode ?? (0777 - umask()));

        file_put_contents($temporary, $contents);

        rename($temporary, $path);
    }

    /**
     * Replace a string throughout a file.
     *
     * @param string|array<int, string> $search
     * @param string|array<int, string> $replace
     */
    public function replaceInFile(string|array $search, string|array $replace, string $path): void
    {
        file_put_contents($path, str_replace($search, $replace, (string) file_get_contents($path)));
    }

    /** Write data to the start of a file, creating it if absent. */
    public function prepend(string $path, string $data): int|false
    {
        return $this->exists($path)
            ? $this->put($path, $data . $this->get($path))
            : $this->put($path, $data);
    }

    /** Write data to the end of a file. */
    public function append(string $path, string $data, bool $lock = false): int|false
    {
        return file_put_contents($path, $data, FILE_APPEND | ($lock ? LOCK_EX : 0));
    }

    /**
     * Read or set the permissions of a path.
     *
     * Given a mode it sets it; given none it returns the current one as the
     * four-digit octal string a human reads.
     */
    public function chmod(string $path, ?int $mode = null): string|bool
    {
        if ($mode !== null) {
            return chmod($path, $mode);
        }

        return substr(sprintf('%o', fileperms($path)), -4);
    }

    /**
     * Delete one or more files.
     *
     * Every path is attempted even after one fails, so a partial failure still
     * removes what it can, and the return value says whether all of them went.
     *
     * @param string|array<int, string> $paths
     */
    public function delete(string|array $paths): bool
    {
        $success = true;

        foreach ((array) $paths as $path) {
            try {
                if (@unlink($path)) {
                    clearstatcache(false, $path);
                } else {
                    $success = false;
                }
            } catch (\ErrorException) {
                $success = false;
            }
        }

        return $success;
    }

    public function move(string $path, string $target): bool
    {
        return rename($path, $target);
    }

    public function copy(string $path, string $target): bool
    {
        return copy($path, $target);
    }

    /**
     * Link one path to another.
     *
     * Windows has no symlink for the unprivileged, so a directory becomes a
     * junction and a file a hard link — which behave closely enough for the
     * thing this is used for, putting storage/app/public under the document
     * root.
     */
    public function link(string $target, string $link): bool
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return symlink($target, $link);
        }

        return $this->mklink($this->isDirectory($target) ? 'J' : 'H', $target, $link) === 0;
    }

    /**
     * Link one path to another, storing the target relative to the link.
     *
     * A relative link survives the whole tree being moved or mounted
     * elsewhere, which an absolute one does not — the reason a deployed
     * public/storage link is worth making this way.
     *
     * @throws RuntimeException on Windows without the privilege to symlink.
     */
    public function relativeLink(string $target, string $link): bool
    {
        $relative = $this->relativePath($target, dirname($link));

        if (DIRECTORY_SEPARATOR !== '\\') {
            return symlink($this->isFile($target) ? rtrim($relative, '/') : $relative, $link);
        }

        // A junction resolves its target against the working directory rather
        // than against the link, so it cannot hold a relative one. A symlink
        // can, and creating one needs elevation or Developer Mode.
        $status = $this->mklink(
            $this->isDirectory($target) ? 'D' : '',
            rtrim(str_replace('/', '\\', $relative), '\\'),
            $link,
        );

        if ($status !== 0) {
            throw new RuntimeException(
                "Could not create a relative link at [{$link}]. Windows needs a symlink for that, "
                . 'which requires Developer Mode or an elevated process. Use link() for an absolute one.'
            );
        }

        return true;
    }

    /**
     * Run Windows' mklink, returning its exit status.
     *
     * Its complaints go to stderr, which would otherwise print straight past
     * whatever called this.
     */
    protected function mklink(string $mode, string $target, string $link): int
    {
        $flag = $mode === '' ? '' : '/' . $mode . ' ';

        exec(
            'mklink ' . $flag . escapeshellarg($link) . ' ' . escapeshellarg($target) . ' 2>&1',
            $output,
            $status
        );

        return $status;
    }

    /**
     * The path to $target as seen from $from.
     *
     * Computed rather than taken from symfony/filesystem, which is not a
     * dependency here.
     */
    protected function relativePath(string $target, string $from): string
    {
        $split = static fn (string $path): array => array_values(array_filter(
            explode('/', str_replace('\\', '/', $path)),
            static fn (string $segment): bool => $segment !== '' && $segment !== '.'
        ));

        $to = $split($target);
        $base = $split($from);

        while ($to !== [] && $base !== [] && $to[0] === $base[0]) {
            array_shift($to);
            array_shift($base);
        }

        $up = array_fill(0, count($base), '..');

        $path = implode('/', array_merge($up, $to));

        return $this->isDirectory($target) ? $path . '/' : $path;
    }

    // ─── Describing ─────────────────────────────────────────

    /** The filename without its extension. */
    public function name(string $path): string
    {
        return pathinfo($path, PATHINFO_FILENAME);
    }

    /** The last component of a path. */
    public function basename(string $path): string
    {
        return pathinfo($path, PATHINFO_BASENAME);
    }

    /** The parent directory of a path. */
    public function dirname(string $path): string
    {
        return pathinfo($path, PATHINFO_DIRNAME);
    }

    public function extension(string $path): string
    {
        return pathinfo($path, PATHINFO_EXTENSION);
    }

    /**
     * The extension the file's contents suggest, ignoring its name.
     *
     * For an upload, whose name is whatever the client sent.
     */
    public function guessExtension(string $path): ?string
    {
        $mime = $this->mimeType($path);

        if ($mime === false) {
            return null;
        }

        // A charset on the end describes the encoding, not the type.
        $mime = trim(explode(';', $mime)[0]);

        return self::EXTENSIONS[$mime] ?? null;
    }

    /** 'file', 'dir', 'link' — whatever the path is. */
    public function type(string $path): string|false
    {
        return @filetype($path);
    }

    /** The mime type read from the file's contents. */
    public function mimeType(string $path): string|false
    {
        if (! function_exists('finfo_open')) {
            throw new RuntimeException('Reading a mime type needs the fileinfo extension.');
        }

        $info = finfo_open(FILEINFO_MIME_TYPE);

        // Not closed: since PHP 8.5 the handle frees itself and finfo_close()
        // is deprecated.
        return $info === false ? false : finfo_file($info, $path);
    }

    public function size(string $path): int|false
    {
        return filesize($path);
    }

    public function lastModified(string $path): int|false
    {
        return filemtime($path);
    }

    public function isDirectory(string $directory): bool
    {
        return is_dir($directory);
    }

    /** Whether a directory holds nothing. */
    public function isEmptyDirectory(string $directory, bool $ignoreDotFiles = false): bool
    {
        if (! $this->isDirectory($directory)) {
            return false;
        }

        $flags = FilesystemIterator::CURRENT_AS_PATHNAME;

        foreach (new FilesystemIterator($directory, $flags | FilesystemIterator::SKIP_DOTS) as $path) {
            if ($ignoreDotFiles && str_starts_with(basename((string) $path), '.')) {
                continue;
            }

            return false;
        }

        return true;
    }

    public function isReadable(string $path): bool
    {
        return is_readable($path);
    }

    public function isWritable(string $path): bool
    {
        return is_writable($path);
    }

    public function isFile(string $file): bool
    {
        return is_file($file);
    }

    /**
     * Whether a path is a link to somewhere else.
     *
     * is_link() covers a symlink but not a Windows junction, which it reports
     * as an ordinary directory. filetype() calls a junction 'unknown', where a
     * real directory is 'dir' and a hard link is 'file' — a hard link being
     * the file rather than a pointer to it, so it is correctly not a link
     * here. readlink() is no use: on Windows it answers for everything.
     *
     * Without this, a tree holding a junction is walked into and deleting the
     * tree reaches whatever the junction points at.
     */
    public function isLink(string $path): bool
    {
        return is_link($path)
            || (DIRECTORY_SEPARATOR === '\\' && @filetype($path) === 'unknown');
    }

    /**
     * Paths matching a shell pattern.
     *
     * @return array<int, string>
     */
    public function glob(string $pattern, int $flags = 0): array
    {
        return glob($pattern, $flags) ?: [];
    }

    // ─── Listing ────────────────────────────────────────────

    /**
     * The files in a directory.
     *
     * @param bool $hidden Whether dotfiles are included.
     * @param bool $recursive Whether to descend into subdirectories.
     * @return array<int, SplFileInfo> Sorted by path, so the order is stable.
     */
    public function files(string $directory, bool $hidden = false, bool $recursive = false): array
    {
        return $this->entries($directory, $hidden, $recursive, files: true);
    }

    /**
     * Every file under a directory, however deep.
     *
     * @return array<int, SplFileInfo>
     */
    public function allFiles(string $directory, bool $hidden = false): array
    {
        return $this->files($directory, $hidden, recursive: true);
    }

    /**
     * The subdirectories of a directory.
     *
     * Dot directories are left out, the same as {@see files()} leaves out
     * dotfiles — a .git or a .cache is rarely what a caller walking a tree
     * means to find.
     *
     * @return array<int, string>
     */
    public function directories(string $directory, bool $recursive = false): array
    {
        return array_map(
            static fn (SplFileInfo $entry): string => $entry->getPathname(),
            $this->entries($directory, hidden: false, recursive: $recursive, files: false),
        );
    }

    /**
     * Every directory under a directory, however deep.
     *
     * @return array<int, string>
     */
    public function allDirectories(string $directory): array
    {
        return $this->directories($directory, recursive: true);
    }

    /**
     * Walk a directory, collecting either files or directories.
     *
     * @return array<int, SplFileInfo>
     */
    protected function entries(string $directory, bool $hidden, bool $recursive, bool $files): array
    {
        if (! $this->isDirectory($directory)) {
            return [];
        }

        $flags = FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO;

        if ($recursive) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, $flags),
                $files ? RecursiveIteratorIterator::LEAVES_ONLY : RecursiveIteratorIterator::SELF_FIRST,
            );
        } else {
            $iterator = new FilesystemIterator($directory, $flags);
        }

        $found = [];

        foreach ($iterator as $entry) {
            /** @var SplFileInfo $entry */
            if ($files !== $entry->isFile()) {
                continue;
            }

            // A hidden file anywhere in the path counts as hidden, so a
            // .git directory does not contribute its contents.
            if (! $hidden && $this->isHidden($entry->getPathname(), $directory)) {
                continue;
            }

            $found[$entry->getPathname()] = $entry;
        }

        ksort($found);

        return array_values($found);
    }

    /** Whether any path segment below the root starts with a dot. */
    protected function isHidden(string $path, string $root): bool
    {
        $relative = trim(str_replace('\\', '/', substr($path, strlen($root))), '/');

        foreach (explode('/', $relative) as $segment) {
            if (str_starts_with($segment, '.')) {
                return true;
            }
        }

        return false;
    }

    // ─── Directories ────────────────────────────────────────

    /** Make a directory if it is not already there. */
    public function ensureDirectoryExists(string $path, int $mode = 0755, bool $recursive = true): bool
    {
        return $this->isDirectory($path) || $this->makeDirectory($path, $mode, $recursive);
    }

    /**
     * Make a directory.
     *
     * @param bool $force Whether to swallow the warning when it cannot be made.
     */
    public function makeDirectory(string $path, int $mode = 0755, bool $recursive = false, bool $force = false): bool
    {
        return $force ? @mkdir($path, $mode, $recursive) : mkdir($path, $mode, $recursive);
    }

    /** Move a directory, optionally replacing what is already at the target. */
    public function moveDirectory(string $from, string $to, bool $overwrite = false): bool
    {
        if ($overwrite && $this->isDirectory($to) && ! $this->deleteDirectory($to)) {
            return false;
        }

        return @rename($from, $to) === true;
    }

    /** Copy a directory and everything under it. */
    public function copyDirectory(string $directory, string $destination, ?int $options = null): bool
    {
        if (! $this->isDirectory($directory)) {
            return false;
        }

        $options ??= FilesystemIterator::SKIP_DOTS;

        $this->ensureDirectoryExists($destination, 0777);

        foreach (new FilesystemIterator($directory, $options) as $item) {
            /** @var SplFileInfo $item */
            $target = $destination . '/' . $item->getBasename();

            $copied = $item->isDir()
                ? $this->copyDirectory($item->getPathname(), $target, $options)
                : $this->copy($item->getPathname(), $target);

            if (! $copied) {
                return false;
            }
        }

        return true;
    }

    /**
     * Delete a directory and everything under it.
     *
     * @param bool $preserve Whether the directory itself is kept.
     */
    public function deleteDirectory(string $directory, bool $preserve = false): bool
    {
        if (! $this->isDirectory($directory)) {
            return false;
        }

        $items = new FilesystemIterator($directory);

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $path = $item->getPathname();

            // Asked before whether it is a directory, because a junction
            // answers that inconsistently. A link is removed, never descended
            // into: following it would reach outside the tree. Which call
            // removes it depends on what it points at, so both are tried.
            if ($this->isLink($path)) {
                @rmdir($path) || @unlink($path);

                continue;
            }

            if ($item->isDir()) {
                $this->deleteDirectory($path);

                continue;
            }

            $this->delete($path);
        }

        unset($items);

        if (! $preserve) {
            @rmdir($directory);
        }

        return true;
    }

    /** Delete the subdirectories of a directory, keeping its files. */
    public function deleteDirectories(string $directory): bool
    {
        $directories = $this->directories($directory);

        foreach ($directories as $each) {
            $this->deleteDirectory($each);
        }

        return $directories !== [];
    }

    /** Empty a directory without removing it. */
    public function cleanDirectory(string $directory): bool
    {
        return $this->deleteDirectory($directory, preserve: true);
    }
}
