<?php

namespace Nitro\Image;

use RuntimeException;

/**
 * Reads, changes and writes an image.
 *
 *     Image::read($path)
 *         ->resize(400, 300)
 *         ->save($thumbnail, quality: 80);
 *
 * Built on GD, which ships with most PHP installations. Every method that
 * changes the image returns the same instance, so a chain reads in the order
 * the work happens.
 */
class Image
{
    /** @param \GdImage $resource */
    private function __construct(
        private mixed $resource,
        private string $format = 'png',
    ) {}

    /**
     * Read an image from disk.
     *
     * @throws RuntimeException When the file is missing or not an image GD reads.
     */
    public static function read(string $path): static
    {
        self::assertGdIsAvailable();

        if (! is_file($path)) {
            throw new RuntimeException("No image at [{$path}].");
        }

        $info = @getimagesize($path);

        if ($info === false) {
            throw new RuntimeException("[{$path}] is not an image this driver reads.");
        }

        $format = match ($info[2]) {
            IMAGETYPE_JPEG => 'jpeg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
            default => throw new RuntimeException('Unsupported image format.'),
        };

        $resource = match ($format) {
            'jpeg' => @imagecreatefromjpeg($path),
            'png' => @imagecreatefrompng($path),
            'gif' => @imagecreatefromgif($path),
            'webp' => @imagecreatefromwebp($path),
        };

        if ($resource === false) {
            throw new RuntimeException("Could not read [{$path}].");
        }

        return new static($resource, $format);
    }

    /** A new blank image. */
    public static function canvas(int $width, int $height, ?string $background = null): static
    {
        self::assertGdIsAvailable();

        $resource = imagecreatetruecolor(max(1, $width), max(1, $height));

        imagealphablending($resource, false);
        imagesavealpha($resource, true);

        $fill = $background === null
            ? imagecolorallocatealpha($resource, 0, 0, 0, 127)
            : self::allocate($resource, $background);

        imagefilledrectangle($resource, 0, 0, $width, $height, $fill);

        return new static($resource, 'png');
    }

    public function width(): int
    {
        return imagesx($this->resource);
    }

    public function height(): int
    {
        return imagesy($this->resource);
    }

    public function format(): string
    {
        return $this->format;
    }

    /** Resize to exactly these dimensions. */
    public function resize(int $width, int $height): static
    {
        return $this->replaceWith($this->scaled(max(1, $width), max(1, $height)));
    }

    /**
     * Resize to fit inside the box, keeping the proportions.
     *
     * The image is never enlarged, so a small original stays as it is rather
     * than being stretched.
     */
    public function scale(int $width, int $height): static
    {
        $ratio = min($width / $this->width(), $height / $this->height(), 1);

        return $this->resize((int) round($this->width() * $ratio), (int) round($this->height() * $ratio));
    }

    /** Fill the box, cropping whatever falls outside it. */
    public function cover(int $width, int $height): static
    {
        $ratio = max($width / $this->width(), $height / $this->height());

        $this->resize((int) ceil($this->width() * $ratio), (int) ceil($this->height() * $ratio));

        return $this->crop(
            $width,
            $height,
            (int) max(0, ($this->width() - $width) / 2),
            (int) max(0, ($this->height() - $height) / 2)
        );
    }

    /** Take a rectangle out of the image. */
    public function crop(int $width, int $height, int $x = 0, int $y = 0): static
    {
        $cropped = imagecrop($this->resource, [
            'x' => $x,
            'y' => $y,
            'width' => max(1, $width),
            'height' => max(1, $height),
        ]);

        if ($cropped === false) {
            throw new RuntimeException('The crop fell outside the image.');
        }

        return $this->replaceWith($cropped);
    }

    /** Rotate by degrees, anticlockwise. */
    public function rotate(float $degrees, ?string $background = null): static
    {
        $colour = $background === null
            ? imagecolorallocatealpha($this->resource, 0, 0, 0, 127)
            : self::allocate($this->resource, $background);

        $rotated = imagerotate($this->resource, $degrees, $colour);

        if ($rotated === false) {
            throw new RuntimeException('Could not rotate the image.');
        }

        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);

        return $this->replaceWith($rotated);
    }

    public function flipHorizontal(): static
    {
        imageflip($this->resource, IMG_FLIP_HORIZONTAL);

        return $this;
    }

    public function flipVertical(): static
    {
        imageflip($this->resource, IMG_FLIP_VERTICAL);

        return $this;
    }

    public function greyscale(): static
    {
        imagefilter($this->resource, IMG_FILTER_GRAYSCALE);

        return $this;
    }

    public function blur(int $amount = 1): static
    {
        for ($i = 0; $i < max(1, $amount); $i++) {
            imagefilter($this->resource, IMG_FILTER_GAUSSIAN_BLUR);
        }

        return $this;
    }

    /**
     * Write the image to disk.
     *
     * The format follows the extension given, or stays as it was read.
     *
     * @throws RuntimeException When the file cannot be written.
     */
    public function save(string $path, int $quality = 90): static
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $format = match ($extension) {
            'jpg', 'jpeg' => 'jpeg',
            'png' => 'png',
            'gif' => 'gif',
            'webp' => 'webp',
            default => $this->format,
        };

        $directory = dirname($path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $written = match ($format) {
            'jpeg' => imagejpeg($this->resource, $path, $quality),
            'png' => imagepng($this->resource, $path, (int) round((100 - $quality) / 11)),
            'gif' => imagegif($this->resource, $path),
            'webp' => imagewebp($this->resource, $path, $quality),
        };

        if ($written === false) {
            throw new RuntimeException("Could not write the image to [{$path}].");
        }

        return $this;
    }

    /** The encoded image as a string. */
    public function encode(?string $format = null, int $quality = 90): string
    {
        $format ??= $this->format;

        ob_start();

        match ($format) {
            'jpeg' => imagejpeg($this->resource, null, $quality),
            'png' => imagepng($this->resource, null, (int) round((100 - $quality) / 11)),
            'gif' => imagegif($this->resource),
            'webp' => imagewebp($this->resource, null, $quality),
            default => imagepng($this->resource),
        };

        return (string) ob_get_clean();
    }

    /** Release the image. */
    public function destroy(): void
    {
        if ($this->resource instanceof \GdImage) {
            imagedestroy($this->resource);
        }
    }

    /** A blank canvas of the requested size, with this image drawn into it. */
    private function scaled(int $width, int $height): \GdImage
    {
        $target = imagecreatetruecolor($width, $height);

        imagealphablending($target, false);
        imagesavealpha($target, true);

        imagecopyresampled(
            $target,
            $this->resource,
            0, 0, 0, 0,
            $width,
            $height,
            $this->width(),
            $this->height()
        );

        return $target;
    }

    private function replaceWith(\GdImage $resource): static
    {
        imagedestroy($this->resource);

        $this->resource = $resource;

        return $this;
    }

    /** Allocate a colour given as #rrggbb. */
    private static function allocate(\GdImage $resource, string $colour): int
    {
        $hex = ltrim($colour, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return (int) imagecolorallocate(
            $resource,
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2))
        );
    }

    /** @throws RuntimeException When GD is not installed. */
    private static function assertGdIsAvailable(): void
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('The image layer needs ext-gd.');
        }
    }
}
