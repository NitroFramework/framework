<?php

namespace Nitro\Facades;

/**
 * Image facade — reads, changes and writes images.
 *
 *   Image::read($path)->cover(400, 300)->save($thumbnail);
 *
 * @method static \Nitro\Image\Image read(string $path)
 * @method static \Nitro\Image\Image canvas(int $width, int $height, ?string $background = null)
 */
class Image extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'image';
    }
}
