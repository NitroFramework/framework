<?php

namespace Nitro\Mail;

use Closure;
use RuntimeException;

/**
 * A file to send with a message, named by where it comes from.
 *
 *     Attachment::fromPath('/tmp/invoice.pdf')->as('invoice.pdf')
 *     Attachment::fromData(fn () => $pdf, 'invoice.pdf')->withMime('application/pdf')
 *
 * Nothing is read until the attachment is added to a message, so a
 * mailable that is queued carries the path rather than the file.
 */
class Attachment
{
    /** The name the recipient sees, or null to use the source's. */
    public ?string $as = null;

    /** The content type, or null to infer it. */
    public ?string $mime = null;

    /** @param Closure(): array{0: string, 1: string} $resolver Content and default name. */
    private function __construct(private Closure $resolver) {}

    /** A file on the local disk. */
    public static function fromPath(string $path): static
    {
        return new static(static function () use ($path): array {
            if (! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException("Attachment [{$path}] does not exist or cannot be read.");
            }

            return [(string) file_get_contents($path), basename($path)];
        });
    }

    /**
     * Content built on demand.
     *
     * The callback runs when the attachment is added, not now, so a
     * report nobody ends up sending is never built.
     *
     * @param Closure(): string $data
     */
    public static function fromData(Closure $data, string $name): static
    {
        return (new static(static fn (): array => [$data(), $name]))->as($name);
    }

    /** A file on the default storage disk. */
    public static function fromStorage(string $path): static
    {
        return static::fromStorageDisk(null, $path);
    }

    /** A file on a named storage disk. */
    public static function fromStorageDisk(?string $disk, string $path): static
    {
        return new static(static function () use ($disk, $path): array {
            $storage = \app('filesystem')->disk($disk);

            if (! $storage->exists($path)) {
                throw new RuntimeException("Attachment [{$path}] does not exist on disk [" . ($disk ?? 'default') . '].');
            }

            return [(string) $storage->get($path), basename($path)];
        });
    }

    /** A file the request uploaded. */
    public static function fromUploadedFile(object $file): static
    {
        return new static(static function () use ($file): array {
            $path = method_exists($file, 'path') ? $file->path() : (string) $file;
            $name = method_exists($file, 'clientName') ? $file->clientName() : basename($path);

            return [(string) file_get_contents($path), $name];
        });
    }

    /** Name the file as the recipient will see it. */
    public function as(?string $name): static
    {
        $this->as = $name;

        return $this;
    }

    /** State the content type rather than letting it be inferred. */
    public function withMime(?string $mime): static
    {
        $this->mime = $mime;

        return $this;
    }

    /**
     * Read the file and add it to a message.
     */
    public function attachTo(Message $message): Message
    {
        [$content, $name] = ($this->resolver)();

        $name = $this->as ?? $name;

        return $message->attachData($content, $name, $this->mime ?? $this->guessMime($name));
    }

    /** Whether two attachments name the same file the same way. */
    public function isEquivalent(self $other): bool
    {
        return $this->as === $other->as && $this->mime === $other->mime;
    }

    /**
     * The content type a file name implies.
     *
     * A short table rather than a full one: an unrecognised extension
     * gets the generic type, which every client handles by offering to
     * save the file.
     */
    private function guessMime(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'csv' => 'text/csv',
            'txt' => 'text/plain',
            'html', 'htm' => 'text/html',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'zip' => 'application/zip',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ics' => 'text/calendar',
            default => 'application/octet-stream',
        };
    }
}
