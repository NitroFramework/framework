<?php

namespace Nitro\Validation\Rules;

use Nitro\Http\UploadedFile;

/**
 * The upload's client extension is one of those listed.
 *
 * Checks the filename only; pair with `mimes` to check the contents too.
 *
 * Usage: 'doc' => 'file|extensions:pdf,docx'
 */
class Extensions extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        if (! $this->value instanceof UploadedFile) {
            return false;
        }

        $extension = strtolower($this->value->getClientOriginalExtension());

        $allowed = array_map(
            static fn ($item) => strtolower(ltrim(trim((string) $item), '.')),
            $this->parameters
        );

        return in_array($extension, $allowed, true);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must have one of these extensions: ' . implode(', ', $this->parameters) . '.');
    }
}
