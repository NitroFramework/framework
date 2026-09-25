<?php

namespace Nitro\Http\Testing;

use Nitro\Http\UploadedFile;

/**
 * An uploaded file made up for a test.
 *
 *     $avatar = UploadedFile::fake()->image('avatar.png', 200, 200);
 *     $report = UploadedFile::fake()->create('report.pdf', 512, 'application/pdf');
 *
 * Backed by a real temporary file, so code that reads, moves or stores the
 * upload works on it unchanged. The size and MIME type it reports can be set
 * without writing that many bytes, so a test of a size or type rule does not
 * need a file of that size or type.
 */
class File extends UploadedFile
{
    /** The size to report, in bytes, instead of the file's own. */
    public ?int $sizeToReport = null;

    /** The MIME type to report, instead of the one its name implies. */
    public ?string $mimeTypeToReport = null;

    /**
     * @param string   $name     The client filename it arrives under.
     * @param resource $tempFile The temporary file holding its contents. Kept
     *                           here, because the file is deleted when the
     *                           handle closes.
     */
    public function __construct(public string $name, public $tempFile)
    {
        parent::__construct($this->tempFilePath(), $name, $this->getMimeType(), UPLOAD_ERR_OK, true);
    }

    /** A file of the given size, in kilobytes, or with the given contents. */
    public static function create(string $name, int|string $kilobytes = 0): File
    {
        return (new FileFactory())->create($name, $kilobytes);
    }

    public static function createWithContent(string $name, string $content): File
    {
        return (new FileFactory())->createWithContent($name, $content);
    }

    /** A real image of the given dimensions. */
    public static function image(string $name, int $width = 10, int $height = 10): File
    {
        return (new FileFactory())->image($name, $width, $height);
    }

    /** Report this size, in kilobytes. */
    public function size(int $kilobytes): static
    {
        $this->sizeToReport = $kilobytes * 1024;

        return $this;
    }

    public function getSize(): int|false
    {
        return $this->sizeToReport ?: parent::getSize();
    }

    /** Report this MIME type. */
    public function mimeType(string $mimeType): static
    {
        $this->mimeTypeToReport = $mimeType;

        return $this;
    }

    /**
     * The type set with mimeType(), otherwise the one the name implies.
     *
     * Not sniffed from the contents, unlike a real upload: a fake file is
     * usually empty, and the test means the type it named.
     */
    public function getMimeType(): ?string
    {
        return $this->mimeTypeToReport ?: MimeType::from($this->name);
    }

    protected function tempFilePath(): string
    {
        return stream_get_meta_data($this->tempFile)['uri'];
    }
}
