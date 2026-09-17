<?php

namespace Nitro\Validation\Rules;

use Nitro\Http\UploadedFile;

/**
 * The upload's sniffed MIME type matches one listed — `mimetypes:image/jpeg`.
 *
 * A trailing /* matches a whole group: `mimetypes:image/*`.
 */
class Mimetypes extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        if (! $this->value instanceof UploadedFile || ! $this->value->isValid()) {
            return false;
        }

        $mime = strtolower((string) $this->value->getMimeType());

        if ($mime === '') {
            return false;
        }

        foreach ($this->parameters as $allowed) {
            $allowed = strtolower(trim($allowed));

            if ($allowed === $mime) {
                return true;
            }

            if (str_ends_with($allowed, '/*')
                && str_starts_with($mime, substr($allowed, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    public function message(): string
    {
        return $this->replaceMessage(
            'The {attribute} must be a file of type: ' . implode(', ', $this->parameters) . '.'
        );
    }
}
