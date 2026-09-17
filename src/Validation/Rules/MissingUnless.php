<?php

namespace Nitro\Validation\Rules;

/**
 * The field is absent unless another field holds one of the given values.
 *
 * Usage: 'id' => 'missing_unless:mode,update'
 */
class MissingUnless extends AbstractRule
{
    public function passes(): bool
    {
        return $this->conditionMatches() || ! $this->valueExists();
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must not be present unless ' . $this->getParameter(0) . ' has that value.');
    }
}
