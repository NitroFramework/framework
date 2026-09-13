<?php

namespace Nitro\Validation\Rules;

use Nitro\Database\DB;

/**
 * The value must already exist in a column.
 *
 * Usage: 'course_id' => 'required|exists:courses,id'
 *
 * The column defaults to the field's own name, so 'exists:courses' checks a
 * `course` column. Queried through the query builder directly rather than by
 * guessing a model class from the table name: the check is about a row, not
 * about a model, and a table with no model (a pivot, most usefully) has to work.
 */
class Exists extends AbstractRule
{
    public function passes(): bool
    {
        // An absent value is the business of `required`. Failing it here too
        // would report one missing field as two errors.
        if ($this->isEmpty($this->value)) {
            return true;
        }

        $table = (string) $this->getParameter(0);
        $column = (string) ($this->getParameter(1) ?? $this->attribute);

        $this->guardIdentifier($table);
        $this->guardIdentifier($column);

        return DB::table($table)->where($column, $this->value)->exists();
    }

    public function message(): string
    {
        return $this->replaceMessage('The selected {attribute} is invalid.');
    }

    /**
     * Defence in depth. These reach a query builder; rejecting anything that is
     * not a plain identifier means a misconfigured rule cannot become an
     * injection vector even if the builder's escaping ever regresses. Same
     * guard as {@see Unique}.
     */
    private function guardIdentifier(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException(
                "Invalid table or column in exists rule: {$identifier}"
            );
        }
    }
}
