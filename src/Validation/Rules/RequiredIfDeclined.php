<?php

namespace Nitro\Validation\Rules;

/**
 * The value is required when another field was declined.
 *
 * Usage: 'reason' => 'required_if_declined:terms'
 */
class RequiredIfDeclined extends AbstractRule
{
    public function passes(): bool
    {
        $other = $this->otherValue((string) $this->getParameter(0));

        if (! in_array($other, ['no', 'off', '0', 0, false, 'false'], true)) {
            return true;
        }

        return ! $this->isEmpty($this->value);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} is required when ' . $this->getParameter(0) . ' is declined.');
    }
}
