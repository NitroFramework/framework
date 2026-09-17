<?php

namespace Nitro\Validation\Rules;

use Nitro\Http\MimeTypes;
use Nitro\Http\UploadedFile;

/**
 * The value is an uploaded image.
 *
 * Decided by the sniffed MIME type, never the client-supplied one or the
 * filename — both are attacker-controlled, and a .jpg extension on a PHP
 * script is the oldest upload bug there is.
 */
class ImageRule extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        if (! $this->value instanceof UploadedFile || ! $this->value->isValid()) {
            return false;
        }

        $mime = $this->value->getMimeType();

        // SVG is an image but carries script; it is excluded unless asked for
        // explicitly with mimes:svg.
        if ($mime === 'image/svg+xml') {
            return false;
        }

        return $mime !== null && MimeTypes::isImage($mime);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be an image.');
    }
}
