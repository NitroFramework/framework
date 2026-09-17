<?php

namespace Nitro\Validation\Rules;

/**
 * The field is empty when another field holds one of the given values.
 *
 * Usage: 'discount' => 'prohibited_if:plan,free'
 */
class ProhibitedIf extends AbstractRule
{
    public function passes(): bool
    {
        return ! $this->conditionMatches() || $this->isEmpty($this->value);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} is prohibited when ' . $this->getParameter(0) . ' has that value.');
    }
}
