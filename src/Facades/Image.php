<?php

namespace Nitro\Facades;

use Nitro\Image\Image as BaseImage;

/**
 * Image facade — reads, changes and writes images.
 *
 *   Image::read($path)->cover(400, 300)->save($thumbnail);
 *
 * An image is opened through a static factory and has no shared instance to
 * resolve, so this extends the real class rather than proxying a container
 * binding. The inherited statics are real, which is why an IDE resolves them
 * with no @method hints.
 */
class Image extends BaseImage
{
}
