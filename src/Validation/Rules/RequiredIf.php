<?php

namespace Nitro\Validation\Rules;

use Nitro\Support\Arr;

/**
 * The value is required when another field holds one of the given values.
 *
 * Usage: 'purchase_order' => 'required_if:payment_method,invoice'
 *        'reason'         => 'required_if:status,rejected,withdrawn'
 *
 * An implicit rule — it runs even when the field is absent, which is the whole
 * point: the Validator's IMPLICIT_RULES list already names it.
 *
 * Loose comparison against the other field, because a form posts '1' where the
 * rule string says 1 and the two must agree.
 */
class RequiredIf extends AbstractRule
{
    public function passes(): bool
    {
        $other = Arr::get($this->data, (string) $this->getParameter(0));
        $triggers = array_slice($this->parameters, 1);

        foreach ($triggers as $trigger) {
            if ($other == $trigger) {
                return !$this->isEmpty($this->value);
            }
        }

        return true;
    }

    public function message(): string
    {
        $other = $this->getParameter(0);

        return $this->replaceMessage("The {attribute} is required when {$other} is {$this->triggerList()}.");
    }

    private function triggerList(): string
    {
        return implode(' or ', array_slice($this->parameters, 1));
    }
}
