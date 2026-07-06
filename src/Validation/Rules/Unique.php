<?php

namespace Nitro\Validation\Rules;

/**
 * Unique Rule
 * 
 * Validates that a value is unique in the database
 * 
 * Usage: email => 'required|email|unique:users,email'
 * 
 * For updates, pass the ID in data array:
 *   $data['id'] = 123;
 *   Will exclude ID 123 from the uniqueness check
 */
class Unique extends AbstractRule
{
    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        $table = $this->getParameter(0);
        $column = $this->getParameter(1);

        if (!$table || !$column) {
            throw new \InvalidArgumentException(
                'The unique rule requires table and column parameters: unique:table,column'
            );
        }

        // Defense-in-depth: parameters end up in a query builder; reject anything
        // that isn't a plain identifier so a misconfigured rule can't become a
        // SQL injection vector even if the builder's escaping ever regresses.
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) ||
            !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            throw new \InvalidArgumentException(
                "Invalid table or column in unique rule: {$table}, {$column}"
            );
        }

        $modelClass = $this->getModelClass($table);

        if (!$modelClass || !class_exists($modelClass)) {
            // Fail closed: a misconfigured rule should surface, not silently
            // let duplicates through.
            throw new \RuntimeException(
                "Unique rule could not locate a model for table [{$table}]."
            );
        }

        $query = $modelClass::query()->where($column, $this->value);

        if (isset($this->data['id'])) {
            $query->where('id', '<>', $this->data['id']);
        }

        return !$query->exists();
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} has already been taken.');
    }

    /**
     * Convert table name to model class name
     * 
     * students -> App\Models\Student
     * teachers -> App\Models\Teacher
     */
    protected function getModelClass(string $table): ?string
    {
        // Remove trailing 's' if plural
        $singular = rtrim($table, 's');

        // Convert to PascalCase
        $modelName = ucfirst($singular);

        // Build full class name
        $modelClass = "App\\Models\\{$modelName}";

        return class_exists($modelClass) ? $modelClass : null;
    }
}