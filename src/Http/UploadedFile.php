<?php

namespace Nitro\Http;

use RuntimeException;
use SplFileInfo;

/**
 * A single uploaded file.
 *
 * Wraps the temporary path PHP wrote, plus the client-supplied name and MIME
 * type, which are attacker-controlled and must never be trusted for anything
 * but display. Use {@see getMimeType()} for the sniffed type and
 * {@see hashName()} for a storage name.
 */
class UploadedFile extends SplFileInfo
{
    /**
     * @param string  $path         Temporary path PHP wrote the upload to.
     * @param string  $originalName Client-supplied filename. Untrusted.
     * @param ?string $mimeType     Client-supplied MIME type. Untrusted.
     * @param int     $error        One of the UPLOAD_ERR_* constants.
     * @param bool    $test         Skip is_uploaded_file()/move_uploaded_file(),
     *                              so tests can construct one from a plain file.
     */
    /**
     * @param ?int $declaredSize Size PHP reported for the upload. Only consulted
     *                           when the file itself cannot be stat'd.
     */
    public function __construct(
        string $path,
        private string $originalName,
        private ?string $mimeType = null,
        private int $error = UPLOAD_ERR_OK,
        private bool $test = false,
        private ?int $declaredSize = null,
    ) {
        parent::__construct($path);
    }

    // ─── Client-supplied metadata (untrusted) ─────────────

    public function getClientOriginalName(): string
    {
        return $this->originalName;
    }

    public function getClientOriginalExtension(): string
    {
        return pathinfo($this->originalName, PATHINFO_EXTENSION);
    }

    public function getClientMimeType(): ?string
    {
        return $this->mimeType;
    }

    /**
     * Size in bytes, or false when the upload failed.
     *
     * Measured from the file on disk, which is authoritative. The size PHP
     * reported is only used when there is no file to measure.
     */
    public function getSize(): int|false
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            return false;
        }

        if (is_file($this->getPathname())) {
            return parent::getSize();
        }

        return $this->declaredSize ?? false;
    }

    // ─── Sniffed metadata (trustworthy) ───────────────────

    /** The MIME type read from the file's contents, not from the client. */
    public function getMimeType(): ?string
    {
        if (! is_file($this->getPathname())) {
            return null;
        }

        if (! function_exists('finfo_open')) {
            return $this->mimeType;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return $this->mimeType;
        }

        $type = finfo_file($finfo, $this->getPathname());
        finfo_close($finfo);

        return $type === false ? null : $type;
    }

    /** Extension implied by the sniffed MIME type, falling back to the client's. */
    public function guessExtension(): ?string
    {
        $extension = MimeTypes::extension((string) $this->getMimeType());

        return $extension ?? ($this->getClientOriginalExtension() ?: null);
    }

    /** The client extension. Kept separate from guessExtension() deliberately. */
    public function extension(): string
    {
        return $this->getClientOriginalExtension();
    }

    // ─── Validity ─────────────────────────────────────────

    public function getError(): int
    {
        return $this->error;
    }

    public function isValid(): bool
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            return false;
        }

        return $this->test ? is_file($this->getPathname()) : is_uploaded_file($this->getPathname());
    }

    public function getErrorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_OK         => 'The file uploaded successfully.',
            UPLOAD_ERR_INI_SIZE   => 'The file exceeds the upload_max_filesize directive.',
            UPLOAD_ERR_FORM_SIZE  => 'The file exceeds the MAX_FILE_SIZE directive in the form.',
            UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'The temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'The file could not be written to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload.',
            default               => 'The file could not be uploaded.',
        };
    }

    // ─── Naming ───────────────────────────────────────────

    /**
     * A random storage name keeping the guessed extension.
     *
     * The client name is never reused: it can collide, can contain path
     * separators, and can carry an extension that disagrees with the contents.
     */
    public function hashName(?string $path = null): string
    {
        $hash = bin2hex(random_bytes(20));
        $extension = $this->guessExtension();
        $name = $hash . ($extension ? '.' . $extension : '');

        return $path ? rtrim($path, '/') . '/' . $name : $name;
    }

    public function path(): string
    {
        return $this->getPathname();
    }

    // ─── Storage ──────────────────────────────────────────

    /**
     * Store on a disk under a random name.
     *
     * @param  array<string, mixed>|string $options Disk name, or an options array.
     * @return string|false The stored path relative to the disk root.
     */
    public function store(string $path = '', array|string $options = []): string|false
    {
        return $this->storeAs($path, $this->hashName(), $options);
    }

    /** Store on a disk, publicly visible. */
    public function storePublicly(string $path = '', array|string $options = []): string|false
    {
        $options = $this->normalizeOptions($options);
        $options['visibility'] = 'public';

        return $this->storeAs($path, $this->hashName(), $options);
    }

    /** Store on a disk under $name, publicly visible. */
    public function storePubliclyAs(string $path, string $name, array|string $options = []): string|false
    {
        $options = $this->normalizeOptions($options);
        $options['visibility'] = 'public';

        return $this->storeAs($path, $name, $options);
    }

    /**
     * Store on a disk under an explicit name.
     *
     * @return string|false The stored path, or false if the upload was invalid.
     */
    public function storeAs(string $path, string $name, array|string $options = []): string|false
    {
        if (! $this->isValid()) {
            return false;
        }

        $options = $this->normalizeOptions($options);
        $disk = $options['disk'] ?? null;
        unset($options['disk']);

        return app('filesystem')->disk($disk)->putFileAs($path, $this->getPathname(), $name, $options);
    }

    /**
     * Move the upload to a directory on the local filesystem.
     *
     * Prefer store()/storeAs(), which go through the configured disk.
     */
    public function move(string $directory, ?string $name = null): static
    {
        if (! $this->isValid()) {
            throw new RuntimeException(
                "Cannot move an invalid upload: {$this->getErrorMessage()}"
            );
        }

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create directory: {$directory}");
        }

        $target = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . ($name ?? $this->hashName());

        $moved = $this->test
            ? rename($this->getPathname(), $target)
            : move_uploaded_file($this->getPathname(), $target);

        if (! $moved) {
            throw new RuntimeException("Cannot move uploaded file to: {$target}");
        }

        @chmod($target, 0644);

        return new static($target, $this->originalName, $this->mimeType, UPLOAD_ERR_OK, true);
    }

    /** Build one from a plain path, for tests. */
    public static function fake(string $path, ?string $originalName = null): static
    {
        return new static(
            $path,
            $originalName ?? basename($path),
            null,
            UPLOAD_ERR_OK,
            true
        );
    }

    /** @param array<string, mixed>|string $options */
    private function normalizeOptions(array|string $options): array
    {
        return is_string($options) ? ['disk' => $options] : $options;
    }
}
