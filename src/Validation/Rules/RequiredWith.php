<?php

namespace Nitro\Validation\Rules;

use Nitro\Support\Arr;

/**
 * The value is required when any of the named fields is present.
 *
 * Usage: 'postcode' => 'required_with:address_line_1'
 *
 * Present, not merely set: a field submitted empty does not trigger this, or an
 * untouched optional address block would demand a postcode.
 */
class RequiredWith extends AbstractRule
{
    public function passes(): bool
    {
        foreach ($this->parameters as $field) {
            if (!$this->isEmpty(Arr::get($this->data, (string) $field))) {
                return !$this->isEmpty($this->value);
            }
        }

        return true;
    }

    public function message(): string
    {
        return $this->replaceMessage(
            'The {attribute} is required when ' . implode(' / ', $this->parameters) . ' is present.'
        );
    }
}
