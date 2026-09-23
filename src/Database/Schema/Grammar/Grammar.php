<?php

namespace Nitro\Database\Schema\Grammar;

use InvalidArgumentException;
use Nitro\Database\Query\RawExpression;
use Nitro\Database\Schema\Blueprint;

/**
 * Turns a {@see Blueprint} into DDL.
 *
 * A Blueprint records what a table should look like in the abstract — a
 * 'string' of length 255, not a VARCHAR(255) — and a grammar states it in one
 * engine's terms. This base speaks MySQL, which the other grammars amend
 * rather than restate.
 */
class Grammar
{
    /** The character this engine encloses an identifier with. */
    protected string $identifierQuote = '`';

    /** Appended to CREATE TABLE, where the engine takes such a thing. */
    protected string $tableSuffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    /** Whether named indexes can be stated inside CREATE TABLE. */
    protected bool $inlineIndexes = true;

    /** Whether a column definition may carry UNSIGNED and an inline COMMENT. */
    protected bool $supportsUnsigned = true;

    protected bool $supportsInlineComment = true;

    /** Whether ALTER TABLE ADD COLUMN accepts a positional AFTER. */
    protected bool $supportsColumnPosition = true;

    // ─── Types ────────────────────────────────────────────

    /**
     * The engine's spelling of one column type.
     *
     * @param array<string, mixed> $column
     */
    public function typeFor(array $column): string
    {
        $parameters = $column['parameters'] ?? [];

        return match ($column['type']) {
            'id' => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'string' => 'VARCHAR(' . $parameters['length'] . ')',
            'char' => 'CHAR(' . $parameters['length'] . ')',
            'text' => 'TEXT',
            'tinyText' => 'TINYTEXT',
            'mediumText' => 'MEDIUMTEXT',
            'longText' => 'LONGTEXT',
            'integer' => 'INT',
            'tinyInteger' => 'TINYINT',
            'smallInteger' => 'SMALLINT',
            'mediumInteger' => 'MEDIUMINT',
            'bigInteger' => 'BIGINT',
            'unsignedBigInteger' => 'BIGINT UNSIGNED',
            'float' => 'FLOAT(' . $parameters['precision'] . ',' . $parameters['scale'] . ')',
            'double' => 'DOUBLE',
            'decimal' => 'DECIMAL(' . $parameters['precision'] . ',' . $parameters['scale'] . ')',
            'boolean' => 'TINYINT(1)',
            'date' => 'DATE',
            'datetime' => 'DATETIME',
            'time' => 'TIME',
            'timestamp' => 'TIMESTAMP',
            'year' => 'YEAR',
            'binary' => 'BLOB',
            'json' => 'JSON',
            'jsonb' => 'JSON',
            'uuid' => 'CHAR(36)',
            'ulid' => 'CHAR(26)',
            'ipAddress' => 'VARCHAR(45)',
            'macAddress' => 'VARCHAR(17)',
            'enum' => 'ENUM(' . $this->quoteValues($parameters['allowed']) . ')',
            'raw' => $parameters['sql'],
            default => throw new InvalidArgumentException("Unknown column type: {$column['type']}"),
        };
    }

    /** @param array<int, mixed> $values */
    protected function quoteValues(array $values): string
    {
        return implode(', ', array_map(
            static fn ($value): string => "'" . str_replace("'", "''", (string) $value) . "'",
            $values,
        ));
    }

    // ─── Statements ───────────────────────────────────────

    public function compileCreate(Blueprint $blueprint): string
    {
        $parts = [];

        foreach ($blueprint->getColumns() as $column) {
            $parts[] = $this->compileColumn($column);
        }

        foreach ($blueprint->getCommands() as $command) {
            $parts[] = $command;
        }

        if ($this->inlineIndexes) {
            foreach ($blueprint->normalizedIndexes() as $index) {
                $parts[] = ($index['unique'] ? 'UNIQUE INDEX ' : 'INDEX ')
                    . $this->quote($index['name'])
                    . ' (' . $this->columnize($index['cols']) . ')';
            }
        }

        return 'CREATE TABLE ' . $this->quote($blueprint->getTable())
            . " (\n    " . implode(",\n    ", $parts) . ")\n" . $this->tableSuffix;
    }

    /**
     * Statements that must follow the CREATE, for an engine that cannot state
     * its indexes inside it.
     *
     * @return array<int, string>
     */
    public function compilePostCreate(Blueprint $blueprint): array
    {
        if ($this->inlineIndexes) {
            return [];
        }

        $table = $this->quote($blueprint->getTable());
        $statements = [];

        foreach ($blueprint->normalizedIndexes() as $index) {
            $statements[] = ($index['unique'] ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ')
                . $this->quote($index['name']) . " ON {$table} ("
                . $this->columnize($index['cols']) . ')';
        }

        return $statements;
    }

    /** @return array<int, string> */
    public function compileAlter(Blueprint $blueprint): array
    {
        $table = $this->quote($blueprint->getTable());
        $statements = [];

        foreach ($blueprint->getColumns() as $column) {
            $after = $this->supportsColumnPosition && $column['after']
                ? ' AFTER ' . $this->quote($column['after'])
                : '';

            $statements[] = "ALTER TABLE {$table} ADD COLUMN " . $this->compileColumn($column) . $after;
        }

        foreach ($blueprint->getCommands() as $command) {
            $statements[] = "ALTER TABLE {$table} "
                . ($this->commandNeedsAddPrefix($command) ? 'ADD ' : '') . $command;
        }

        foreach ($blueprint->normalizedIndexes() as $index) {
            $columns = $this->columnize($index['cols']);

            $statements[] = $this->inlineIndexes
                ? "ALTER TABLE {$table} ADD " . ($index['unique'] ? 'UNIQUE INDEX ' : 'INDEX ')
                    . $this->quote($index['name']) . " ({$columns})"
                : ($index['unique'] ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ')
                    . $this->quote($index['name']) . " ON {$table} ({$columns})";
        }

        return $statements;
    }

    /**
     * Whether an ALTER command needs an "ADD " in front.
     *
     * A drop or a rename already carries its verb; a constraint clause does not.
     */
    protected function commandNeedsAddPrefix(string $command): bool
    {
        $upper = ltrim(strtoupper($command));

        foreach (['DROP ', 'RENAME ', 'MODIFY ', 'CHANGE ', 'ALTER '] as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return false;
            }
        }

        return true;
    }

    // ─── Columns ──────────────────────────────────────────

    /**
     * @param array<string, mixed> $column
     */
    public function compileColumn(array $column): string
    {
        $type = $this->typeFor($column);
        $sql = $this->quote($column['name']) . ' ' . $type;

        // A generated key is NOT NULL by definition, and already says so.
        $isPrimaryKey = str_contains($type, 'PRIMARY KEY');

        if ($this->supportsUnsigned && $column['unsigned'] && !str_contains($type, 'UNSIGNED')) {
            $sql .= ' UNSIGNED';
        }

        if (!$isPrimaryKey) {
            $sql .= $column['nullable'] ? ' NULL' : ' NOT NULL';
        }

        if (!empty($column['has_default'])) {
            $sql .= ' DEFAULT ' . $this->compileDefault($column['default']);
        }

        if ($column['unique'] && !$isPrimaryKey) {
            $sql .= ' UNIQUE';
        }

        if ($column['comment'] && $this->supportsInlineComment) {
            $sql .= " COMMENT '" . str_replace("'", "''", $column['comment']) . "'";
        }

        return $sql;
    }

    /**
     * Render a DEFAULT literal.
     *
     * A RawExpression is written through untouched, which is how a function
     * default such as CURRENT_TIMESTAMP reaches the engine as a call rather
     * than as the string 'CURRENT_TIMESTAMP'.
     */
    public function compileDefault(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            $value instanceof RawExpression => (string) $value,
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            default => "'" . str_replace("'", "''", (string) $value) . "'",
        };
    }

    // ─── Identifiers ──────────────────────────────────────

    /**
     * Enclose an identifier after checking its shape.
     *
     * Every table, column and index name the DDL carries goes through here,
     * which keeps a reserved word such as 'order' usable and refuses a name
     * that is not one.
     */
    public function quote(string $identifier): string
    {
        $identifier = trim($identifier);

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException("Invalid identifier: {$identifier}");
        }

        return $this->identifierQuote . $identifier . $this->identifierQuote;
    }

    /** @param array<int, string> $columns */
    public function columnize(array $columns): string
    {
        return implode(', ', array_map([$this, 'quote'], $columns));
    }

    /** The clause that drops a foreign key, which is not spelled alike. */
    public function compileDropForeign(string $name): string
    {
        return 'DROP FOREIGN KEY ' . $this->quote($name);
    }
}
