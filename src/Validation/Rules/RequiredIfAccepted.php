<?php

namespace Nitro\Validation\Rules;

/**
 * The value is required when another field was accepted.
 *
 * Usage: 'card' => 'required_if_accepted:paid_plan'
 */
class RequiredIfAccepted extends AbstractRule
{
    public function passes(): bool
    {
        $other = $this->otherValue((string) $this->getParameter(0));

        if (! in_array($other, ['yes', 'on', '1', 1, true, 'true'], true)) {
            return true;
        }

        return ! $this->isEmpty($this->value);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} is required when ' . $this->getParameter(0) . ' is accepted.');
    }
}
