<?php

namespace Nitro\Http;

/**
 * A response that hands over a file.
 *
 * The body is read from disk when it is sent, not when the response is built,
 * so a 40MB export does not sit in memory while middleware runs — and under a
 * persistent worker, where the process outlives the request, that difference is
 * the difference between a worker that serves downloads all day and one that
 * has to be restarted.
 *
 *     return FileResponse::download($path, 'invoice-2026-0042.pdf');
 *     return FileResponse::inline($path);   // shown in the browser instead
 *
 * A download is not a page, which is why this exists at all: a controller
 * returning a file cannot go through the view layer, and a Livewire component
 * cannot return one at all.
 */
class FileResponse extends Response
{
    /** How much is read and flushed at a time. */
    protected const CHUNK = 8192;

    protected string $path;

    /** 'attachment' (save it) or 'inline' (show it). */
    protected string $disposition;

    /** The name the browser saves it under. */
    protected string $filename;

    protected function __construct(string $path, string $disposition, ?string $filename, array $headers)
    {
        parent::__construct('', self::HTTP_OK, $headers);

        $this->path = $path;
        $this->disposition = $disposition;
        $this->filename = $filename ?? basename($path);
    }

    /** Offer the file as a download. */
    public static function download(string $path, ?string $filename = null, array $headers = []): self
    {
        return new self($path, 'attachment', $filename, $headers);
    }

    /** Show the file in the browser — a PDF preview, an image. */
    public static function inline(string $path, ?string $filename = null, array $headers = []): self
    {
        return new self($path, 'inline', $filename, $headers);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function filename(): string
    {
        return $this->filename;
    }

    public function disposition(): string
    {
        return $this->disposition;
    }

    public function send(): void
    {
        if (! is_file($this->path)) {
            // The row said there was a file and there is not. A 404 is the
            // honest answer: the alternative is a zero-byte download that looks
            // like a corrupt document rather than a missing one.
            (new Response('Not Found', self::HTTP_NOT_FOUND))->send();

            return;
        }

        $this->prepareHeaders();

        if (! headers_sent()) {
            http_response_code($this->statusCode);

            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }

            foreach ($this->cookies as $cookie) {
                header('Set-Cookie: ' . $cookie->toHeader(), false);
            }
        }

        $this->stream();
    }

    /**
     * Content-Type, length and disposition — each only when the caller has not
     * set it, so an explicit header always wins.
     */
    protected function prepareHeaders(): void
    {
        if ($this->header('Content-Type') === null) {
            $this->header('Content-Type', $this->contentType());
        }

        if ($this->header('Content-Length') === null) {
            $size = @filesize($this->path);

            if ($size !== false) {
                $this->header('Content-Length', (string) $size);
            }
        }

        if ($this->header('Content-Disposition') === null) {
            $this->header('Content-Disposition', $this->contentDisposition());
        }
    }

    /**
     * The filename header, in both the plain and the encoded form.
     *
     * A name with a space, a comma or an accent in it breaks the plain form in
     * one browser or another, so the ASCII fallback is quoted and stripped and
     * the real name goes in filename* — which is what RFC 6266 asks for and
     * what every current browser reads.
     */
    protected function contentDisposition(): string
    {
        // A run of non-ASCII bytes becomes one underscore, not one per byte: a
        // single accented letter is two bytes in UTF-8, and "R__le" reads as a
        // mangled name rather than an elided one.
        $fallback = preg_replace('/[^\x20-\x7E]+/', '_', $this->filename) ?? 'file';
        $fallback = str_replace(['"', '\\'], '_', $fallback);

        return sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $this->disposition,
            $fallback,
            rawurlencode($this->filename),
        );
    }

    /** Guessed from the extension, falling back to a generic binary. */
    protected function contentType(): string
    {
        $extension = strtolower(pathinfo($this->path, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'application/pdf',
            'csv' => 'text/csv; charset=utf-8',
            'txt' => 'text/plain; charset=utf-8',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'zip' => 'application/zip',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    /** Read and echo in chunks, so the whole file is never held in memory. */
    protected function stream(): void
    {
        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            return;
        }

        // Push each chunk at the client as it is read — but only when nothing
        // is capturing output. A buffer that is open belongs to somebody who
        // wants the bytes rather than the browser: a test asserting what was
        // served, or a worker runtime collecting a body to hand back. Flushing
        // there would push the file out of their buffer and into the one above.
        $capturing = ob_get_level() > 0;

        while (! feof($handle)) {
            echo fread($handle, self::CHUNK);

            if (! $capturing) {
                flush();
            }
        }

        fclose($handle);
    }

    /**
     * The file's contents.
     *
     * Only for a caller that genuinely needs the bytes — a test asserting what
     * was served, or a worker runtime that hands back a body rather than
     * writing to the output buffer. Sending still streams.
     */
    public function getContent(): string
    {
        return is_file($this->path) ? (string) file_get_contents($this->path) : '';
    }
}
