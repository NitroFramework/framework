<?php

namespace Nitro\Facades;

/**
 * Storage facade — access storage disks.
 *
 *   Storage::put('file.txt', $contents);   Storage::get('file.txt');
 *   Storage::disk('public')->url('avatars/1.png');
 *
 * Paths are relative to the disk's root, which may not be local at all — which
 * is why a URL is asked of the disk rather than composed by the caller. For
 * paths the application already knows in full, reach for {@see File} instead.
 *
 * Calls that name no disk go to the default one.
 *
 * @method static \Nitro\Filesystem\Contracts\Filesystem disk(?string $name = null)
 * @method static \Nitro\Filesystem\Contracts\Filesystem drive(?string $name = null)
 * @method static \Nitro\Filesystem\Contracts\Filesystem cloud()
 * @method static \Nitro\Filesystem\Contracts\Filesystem build(array|string $config)
 * @method static \Nitro\Filesystem\FilesystemManager set(string $name, \Nitro\Filesystem\Contracts\Filesystem $disk)
 * @method static \Nitro\Filesystem\FilesystemManager extend(string $driver, \Closure $callback)
 * @method static \Nitro\Filesystem\FilesystemManager forgetDisk(string|array $name)
 * @method static \Nitro\Filesystem\FilesystemManager purge(?string $name = null)
 * @method static string getDefaultDriver()
 * @method static string getDefaultCloudDriver()
 *
 * @method static bool exists(string $path)
 * @method static bool missing(string $path)
 * @method static bool fileExists(string $path)
 * @method static bool fileMissing(string $path)
 * @method static bool directoryExists(string $directory)
 * @method static bool directoryMissing(string $directory)
 * @method static ?string get(string $path)
 * @method static ?array json(string $path, int $flags = 0)
 * @method static resource|null readStream(string $path)
 * @method static bool put(string $path, mixed $contents, array $options = [])
 * @method static bool writeStream(string $path, mixed $resource, array $options = [])
 * @method static string putFile(string $directory, string $sourcePath, array $options = [])
 * @method static string putFileAs(string $directory, string $sourcePath, string $name, array $options = [])
 * @method static bool prepend(string $path, string $data)
 * @method static bool append(string $path, string $data)
 * @method static bool delete(string|array $paths)
 * @method static bool copy(string $from, string $to)
 * @method static bool move(string $from, string $to)
 * @method static bool copyToDisk(string $from, \Nitro\Filesystem\Contracts\Filesystem $disk, ?string $to = null, array $options = [])
 * @method static bool moveToDisk(string $from, \Nitro\Filesystem\Contracts\Filesystem $disk, ?string $to = null, array $options = [])
 * @method static ?int size(string $path)
 * @method static ?int lastModified(string $path)
 * @method static ?string mimeType(string $path)
 * @method static ?string checksum(string $path, array $options = [])
 * @method static ?string getVisibility(string $path)
 * @method static bool setVisibility(string $path, string $visibility)
 * @method static array files(?string $directory = null, bool $recursive = false)
 * @method static array allFiles(?string $directory = null)
 * @method static array directories(?string $directory = null, bool $recursive = false)
 * @method static array allDirectories(?string $directory = null)
 * @method static bool makeDirectory(string $path)
 * @method static bool deleteDirectory(string $directory)
 * @method static string path(string $path = '')
 * @method static string url(string $path)
 * @method static bool providesTemporaryUrls()
 * @method static string temporaryUrl(string $path, int $seconds = 3600, array $options = [])
 * @method static bool providesTemporaryUploadUrls()
 * @method static array temporaryUploadUrl(string $path, int $seconds = 3600, array $options = [])
 * @method static \Nitro\Filesystem\Contracts\Filesystem buildTemporaryUrlsUsing(\Closure $callback)
 * @method static \Nitro\Filesystem\Contracts\Filesystem buildTemporaryUploadUrlsUsing(\Closure $callback)
 * @method static \Nitro\Http\FileResponse download(string $path, ?string $name = null, array $headers = [])
 * @method static \Nitro\Http\FileResponse response(string $path, ?string $name = null, array $headers = [])
 * @method static array getConfig()
 * @method static mixed when(mixed $condition, callable $callback, ?callable $default = null)
 * @method static mixed unless(mixed $condition, callable $callback, ?callable $default = null)
 *
 * @method static \Nitro\Filesystem\Contracts\Filesystem assertExists(string|array $path, ?string $contents = null)
 * @method static \Nitro\Filesystem\Contracts\Filesystem assertMissing(string|array $path)
 * @method static \Nitro\Filesystem\Contracts\Filesystem assertCount(string $directory, int $count, bool $recursive = false)
 * @method static \Nitro\Filesystem\Contracts\Filesystem assertDirectoryEmpty(string $directory)
 * @method static \Nitro\Filesystem\Contracts\Filesystem assertEmpty()
 *
 * @see \Nitro\Filesystem\FilesystemManager
 * @see \Nitro\Filesystem\Contracts\Filesystem
 */
class Storage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'filesystem';
    }

    /**
     * Point a disk at an empty temporary directory.
     *
     * Not a recorder, unlike the other fakes: code that stores a file almost
     * always reads it back, resizes it, or lists the directory — so a disk
     * that only remembered being called would fail tests for the wrong reason.
     * This is a real local disk, emptied first, somewhere harmless.
     *
     * The assertions are the disk's own: assertExists(), assertMissing(),
     * assertCount(), assertDirectoryEmpty().
     */
    public static function fake(string $disk = 'local'): \Nitro\Filesystem\Contracts\Filesystem
    {
        $root = sys_get_temp_dir() . '/nitro-disk-' . $disk;

        $files = new \Nitro\Filesystem\Filesystem();

        // Emptied rather than reused: a file left by the last test is the kind
        // of thing that makes one test pass only when another ran first.
        $files->deleteDirectory($root);
        $files->ensureDirectoryExists($root);

        $fake = new \Nitro\Filesystem\LocalFilesystem($root, ['root' => $root]);

        app()->resolve('filesystem')->set($disk, $fake);

        return $fake;
    }
}
