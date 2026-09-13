<?php

namespace Nitro\Validation\Rules;

/**
 * The value must be castable to a boolean.
 *
 * Usage: 'marketing_opt_in' => 'boolean'
 *
 * Accepts true, false, 1, 0, '1' and '0', because an unchecked checkbox arrives
 * as the string '0' from a hidden companion field and a checked one as '1'.
 * Nothing else: 'yes', 'on' and '' are rejected rather than quietly read as
 * true, which is how a consent field ends up recording a consent nobody gave.
 */
class BooleanRule extends AbstractRule
{
    public function passes(): bool
    {
        // Not isEmpty(): false and '0' are empty-ish to PHP and are exactly the
        // values this rule exists to accept.
        if ($this->value === null) {
            return true;
        }

        return in_array($this->value, [true, false, 1, 0, '1', '0'], true);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} field must be true or false.');
    }
}
