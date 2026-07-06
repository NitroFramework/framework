<?php

namespace Nitro\Validation;

/**
 * Fluent builders for validation rules — Laravel's `Rule::` surface, for use in
 * array-style rule lists:
 *
 *   'role'  => ['required', Rule::in(['admin', 'editor'])],
 *   'email' => ['required', 'email', Rule::unique('users', 'email')],
 *
 * Each helper returns the rule token the Validator understands, so they compose
 * with plain string rules. (Values containing commas aren't supported in in()
 * — use a custom rule for those.)
 */
class Rule
{
    /** Value must be one of the given options. */
    public static function in(array $values): string
    {
        return 'in:' . implode(',', $values);
    }

    /**
     * Value must be unique in a table/column. For updates, put the row's id in
     * the validated data under 'id' to exclude it (the unique rule honours it).
     */
    public static function unique(string $table, string $column = 'id'): string
    {
        return "unique:{$table},{$column}";
    }
}
