<?php

namespace Nitro\Validation\Rules;

/**
 * The array holds no duplicate values.
 *
 * Comparison is loose by default; pass `strict` for type-sensitive comparison,
 * or `ignore_case` to fold case first.
 *
 * Usage: 'tags' => 'array|distinct'
 */
class Distinct extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        if (! is_array($this->value)) {
            return true;
        }

        $values = $this->value;
        $flags = array_map('strtolower', array_map('strval', $this->parameters));

        if (in_array('ignore_case', $flags, true)) {
            $values = array_map(
                static fn ($item) => is_string($item) ? mb_strtolower($item, 'UTF-8') : $item,
                $values
            );
        }

        $unique = in_array('strict', $flags, true)
            ? array_unique($values, SORT_REGULAR)
            : array_unique($values);

        return count($unique) === count($values);
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} has a duplicate value.');
    }
}
