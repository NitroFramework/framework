<?php

namespace Nitro\Validation\Rules;

use Nitro\Http\UploadedFile;

/**
 * The value is a successfully uploaded file.
 *
 * Checks the upload succeeded, not merely that something arrived: a truncated
 * or oversized upload is present in the request but is not a file anyone
 * should store.
 */
class FileRule extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        return $this->value instanceof UploadedFile && $this->value->isValid();
    }

    public function message(): string
    {
        if ($this->value instanceof UploadedFile && ! $this->value->isValid()) {
            return $this->replaceMessage('The {attribute} failed to upload: ')
                . $this->value->getErrorMessage();
        }

        return $this->replaceMessage('The {attribute} must be a file.');
    }
}
