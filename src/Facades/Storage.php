<?php

namespace Nitro\Facades;

/**
 * Storage facade — access storage disks.
 *
 *   Storage::put('file.txt', $contents);   Storage::get('file.txt');
 *   Storage::disk('public')->url('avatars/1.png');
 *
 * @method static \Nitro\Filesystem\Contracts\Filesystem disk(?string $name = null)
 * @method static bool   exists(string $path)
 * @method static ?string get(string $path)
 * @method static bool   put(string $path, mixed $contents, array $options = [])
 * @method static string putFileAs(string $directory, string $sourcePath, string $name, array $options = [])
 * @method static bool   delete(string|array $paths)
 * @method static ?int   size(string $path)
 * @method static string url(string $path)
 */
class Storage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'filesystem';
    }
}
