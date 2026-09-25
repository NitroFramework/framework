<?php

namespace Nitro\Http\Testing;

use LogicException;

/**
 * Makes fake uploaded files for tests.
 *
 *     UploadedFile::fake()->image('avatar.jpg', 640, 480);
 *     UploadedFile::fake()->create('contract.pdf', 1024, 'application/pdf');
 *     UploadedFile::fake()->createWithContent('rows.csv', "id,name\n1,Ada\n");
 */
class FileFactory
{
    /**
     * A file reporting the given size in kilobytes, and optionally a MIME type.
     * Given a string instead of a size, the file holds that content.
     */
    public function create(string $name, int|string $kilobytes = 0, ?string $mimeType = null): File
    {
        if (is_string($kilobytes)) {
            return $this->createWithContent($name, $kilobytes);
        }

        $file = new File($name, tmpfile());

        $file->sizeToReport = $kilobytes * 1024;
        $file->mimeTypeToReport = $mimeType;

        return $file;
    }

    /** A file holding the given content. */
    public function createWithContent(string $name, string $content): File
    {
        $tmpfile = tmpfile();

        fwrite($tmpfile, $content);

        $file = new File($name, $tmpfile);

        $file->sizeToReport = fstat($tmpfile)['size'];

        return $file;
    }

    /**
     * A real image of the given dimensions, in the format its extension names,
     * or JPEG when the extension is not an image format.
     */
    public function image(string $name, int $width = 10, int $height = 10): File
    {
        return new File($name, $this->generateImage($width, $height, pathinfo($name, PATHINFO_EXTENSION)));
    }

    /**
     * @return resource
     * @throws LogicException When GD, or the format's writer, is missing.
     */
    protected function generateImage(int $width, int $height, string $extension)
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new LogicException('GD extension is not installed.');
        }

        $extension = in_array(strtolower($extension), ['jpeg', 'png', 'gif', 'webp', 'wbmp', 'bmp'], true)
            ? strtolower($extension)
            : 'jpeg';

        if (! function_exists($function = "image{$extension}")) {
            throw new LogicException("{$function} function is not defined and image cannot be generated.");
        }

        $image = imagecreatetruecolor($width, $height);

        ob_start();
        $function($image);
        $contents = ob_get_clean();

        $temp = tmpfile();

        fwrite($temp, $contents);

        return $temp;
    }
}
