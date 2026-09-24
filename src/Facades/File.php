<?php

namespace Nitro\Facades;

/**
 * File facade — the local filesystem, addressed by real paths.
 *
 *   File::get(base_path('composer.json'));
 *   File::ensureDirectoryExists(storage_path('app/exports'));
 *
 * For user uploads and anything that may later live in a bucket, reach for
 * {@see Storage} instead: its paths are relative to a configured disk root.
 *
 * @method static bool exists(string $path)
 * @method static bool missing(string $path)
 * @method static string get(string $path, bool $lock = false)
 * @method static array json(string $path, int $flags = 0, bool $lock = false)
 * @method static string sharedGet(string $path)
 * @method static mixed getRequire(string $path, array $data = [])
 * @method static mixed requireOnce(string $path, array $data = [])
 * @method static \Generator lines(string $path)
 * @method static string|false hash(string $path, string $algorithm = 'md5')
 * @method static bool hasSameHash(string $first, string $second)
 * @method static int|false put(string $path, string $contents, bool $lock = false)
 * @method static void replace(string $path, string $contents, ?int $mode = null)
 * @method static void replaceInFile(string|array $search, string|array $replace, string $path)
 * @method static int|false prepend(string $path, string $data)
 * @method static int|false append(string $path, string $data, bool $lock = false)
 * @method static string|bool chmod(string $path, ?int $mode = null)
 * @method static bool delete(string|array $paths)
 * @method static bool move(string $path, string $target)
 * @method static bool copy(string $path, string $target)
 * @method static bool link(string $target, string $link)
 * @method static bool relativeLink(string $target, string $link)
 * @method static string name(string $path)
 * @method static string basename(string $path)
 * @method static string dirname(string $path)
 * @method static string extension(string $path)
 * @method static ?string guessExtension(string $path)
 * @method static string|false type(string $path)
 * @method static string|false mimeType(string $path)
 * @method static int|false size(string $path)
 * @method static int|false lastModified(string $path)
 * @method static bool isDirectory(string $directory)
 * @method static bool isEmptyDirectory(string $directory, bool $ignoreDotFiles = false)
 * @method static bool isReadable(string $path)
 * @method static bool isWritable(string $path)
 * @method static bool isFile(string $file)
 * @method static array glob(string $pattern, int $flags = 0)
 * @method static \SplFileInfo[] files(string $directory, bool $hidden = false, bool $recursive = false)
 * @method static \SplFileInfo[] allFiles(string $directory, bool $hidden = false)
 * @method static array directories(string $directory, bool $recursive = false)
 * @method static array allDirectories(string $directory)
 * @method static bool ensureDirectoryExists(string $path, int $mode = 0755, bool $recursive = true)
 * @method static bool makeDirectory(string $path, int $mode = 0755, bool $recursive = false, bool $force = false)
 * @method static bool moveDirectory(string $from, string $to, bool $overwrite = false)
 * @method static bool copyDirectory(string $directory, string $destination, ?int $options = null)
 * @method static bool deleteDirectory(string $directory, bool $preserve = false)
 * @method static bool deleteDirectories(string $directory)
 * @method static bool cleanDirectory(string $directory)
 * @method static mixed when(mixed $condition, callable $callback, ?callable $default = null)
 * @method static mixed unless(mixed $condition, callable $callback, ?callable $default = null)
 *
 * @see \Nitro\Filesystem\Filesystem
 */
class File extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'files';
    }
}
