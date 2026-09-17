<?php

namespace Nitro\Validation\Rules;

/**
 * The field is absent when another field holds one of the given values.
 *
 * Usage: 'id' => 'missing_if:mode,create'
 */
class MissingIf extends AbstractRule
{
    public function passes(): bool
    {
        return ! $this->conditionMatches() || ! $this->valueExists();
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} must not be present when ' . $this->getParameter(0) . ' has that value.');
    }
}
