<?php

namespace Nitro\Validation\Rules;

/**
 * The field is empty unless another field holds one of the given values.
 *
 * Usage: 'discount' => 'prohibited_unless:plan,pro'
 */
class ProhibitedUnless extends AbstractRule
{
    public function passes(): bool
    {
        return $this->conditionMatches() || $this->isEmpty($this->value);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} is prohibited unless ' . $this->getParameter(0) . ' has that value.');
    }
}
