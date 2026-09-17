<?php

namespace Nitro\Validation\Rules;

use Nitro\Http\MimeTypes;
use Nitro\Http\UploadedFile;

/**
 * The upload's contents match one of the listed extensions — `mimes:jpg,png`.
 *
 * The extension is derived from the sniffed MIME type, so a file renamed to
 * .jpg fails unless its bytes are actually a JPEG.
 */
class Mimes extends AbstractRule
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

        if ($mime === null) {
            return false;
        }

        $allowed = array_map(
            static fn (string $e) => strtolower(ltrim(trim($e), '.')),
            $this->parameters
        );

        // Every extension the sniffed type may legitimately carry — jpeg
        // reports both 'jpg' and 'jpeg', and mimes:jpg must accept both.
        $actual = MimeTypes::extensions($mime);

        return array_intersect($allowed, $actual) !== [];
    }

    public function message(): string
    {
        return $this->replaceMessage(
            'The {attribute} must be a file of type: ' . implode(', ', $this->parameters) . '.'
        );
    }
}
