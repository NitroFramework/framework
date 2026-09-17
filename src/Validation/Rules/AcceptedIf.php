<?php

namespace Nitro\Validation\Rules;

/**
 * The value is an affirmative when another field holds one of the given values.
 *
 * Usage: 'terms' => 'accepted_if:plan,pro'
 */
class AcceptedIf extends AbstractRule
{
    public function passes(): bool
    {
        if (! $this->conditionMatches()) {
            return true;
        }

        return in_array($this->value, ['yes', 'on', '1', 1, true, 'true'], true);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be accepted when ' . $this->getParameter(0) . ' has that value.');
    }
}
