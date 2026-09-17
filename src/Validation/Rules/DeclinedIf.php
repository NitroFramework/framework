<?php

namespace Nitro\Validation\Rules;

/**
 * The value is a negative when another field holds one of the given values.
 *
 * Usage: 'marketing' => 'declined_if:region,eu'
 */
class DeclinedIf extends AbstractRule
{
    public function passes(): bool
    {
        if (! $this->conditionMatches()) {
            return true;
        }

        return in_array($this->value, ['no', 'off', '0', 0, false, 'false'], true);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be declined when ' . $this->getParameter(0) . ' has that value.');
    }
}
