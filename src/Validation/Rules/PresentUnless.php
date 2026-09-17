<?php

namespace Nitro\Validation\Rules;

/**
 * The field is submitted unless another field holds one of the given values.
 *
 * Usage: 'reason' => 'present_unless:status,approved'
 */
class PresentUnless extends AbstractRule
{
    public function passes(): bool
    {
        return $this->conditionMatches() || $this->valueExists();
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must be present unless ' . $this->getParameter(0) . ' has that value.');
    }
}
