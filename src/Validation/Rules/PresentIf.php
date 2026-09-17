<?php

namespace Nitro\Validation\Rules;

/**
 * The field is submitted when another field holds one of the given values.
 *
 * Usage: 'reason' => 'present_if:status,rejected'
 */
class PresentIf extends AbstractRule
{
    public function passes(): bool
    {
        return ! $this->conditionMatches() || $this->valueExists();
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be present when ' . $this->getParameter(0) . ' has that value.');
    }
}
