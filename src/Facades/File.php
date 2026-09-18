<?php

namespace Nitro\Facades;

/**
 * File facade — reads and writes the local filesystem.
 *
 *   File::get($path);
 *   File::put($path, $contents);
 *
 * @method static bool exists(string $path)
 * @method static string get(string $path)
 * @method static bool put(string $path, string $contents)
 * @method static bool delete(string|array $paths)
 * @method static bool copy(string $from, string $to)
 * @method static bool move(string $from, string $to)
 * @method static array files(string $directory)
 * @method static bool makeDirectory(string $path, int $mode = 0755, bool $recursive = false)
 */
class File extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'filesystem';
    }
}
