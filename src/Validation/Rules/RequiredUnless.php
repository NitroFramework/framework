<?php

namespace Nitro\Validation\Rules;

/**
 * The value is required unless another field holds one of the given values.
 *
 * Usage: 'reason' => 'required_unless:status,approved'
 */
class RequiredUnless extends AbstractRule
{
    public function passes(): bool
    {
        return $this->conditionMatches() || ! $this->isEmpty($this->value);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} is required unless ' . $this->getParameter(0) . ' has that value.');
    }
}
