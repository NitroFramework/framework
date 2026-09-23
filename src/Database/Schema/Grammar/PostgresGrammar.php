<?php

namespace Nitro\Database\Schema\Grammar;

/**
 * PostgreSQL schema grammar.
 *
 * Postgres shares little of MySQL's type vocabulary: there is no LONGTEXT,
 * MEDIUMINT, TINYINT, YEAR, BLOB or DATETIME, no UNSIGNED, and no inline
 * COMMENT. Every one of those is a syntax error rather than a synonym, so the
 * type map here is stated in full rather than inherited.
 */
class PostgresGrammar extends Grammar
{
    protected string $identifierQuote = '"';

    protected string $tableSuffix = '';

    /** Postgres takes its indexes as their own statements. */
    protected bool $inlineIndexes = false;

    /** Width is a property of the type, not a modifier: use bigint over int. */
    protected bool $supportsUnsigned = false;

    /** A comment is its own COMMENT ON statement, not part of the column. */
    protected bool $supportsInlineComment = false;

    protected bool $supportsColumnPosition = false;

    /**
     * @param array<string, mixed> $column
     */
    public function typeFor(array $column): string
    {
        $parameters = $column['parameters'] ?? [];

        return match ($column['type']) {
            // bigserial is the generated key; Postgres has no AUTO_INCREMENT.
            'id' => 'BIGSERIAL PRIMARY KEY',
            'string' => 'VARCHAR(' . $parameters['length'] . ')',
            'char' => 'CHAR(' . $parameters['length'] . ')',
            'text', 'tinyText', 'mediumText', 'longText' => 'TEXT',
            'integer', 'mediumInteger' => 'INTEGER',
            'tinyInteger', 'smallInteger' => 'SMALLINT',
            'bigInteger', 'unsignedBigInteger' => 'BIGINT',
            'float' => 'DOUBLE PRECISION',
            'double' => 'DOUBLE PRECISION',
            'decimal' => 'DECIMAL(' . $parameters['precision'] . ',' . $parameters['scale'] . ')',
            'boolean' => 'BOOLEAN',
            'date' => 'DATE',
            'datetime', 'timestamp' => 'TIMESTAMP(0) WITHOUT TIME ZONE',
            'time' => 'TIME(0) WITHOUT TIME ZONE',
            // No YEAR type; an integer is what the value is.
            'year' => 'INTEGER',
            'binary' => 'BYTEA',
            'json' => 'JSON',
            'jsonb' => 'JSONB',
            'uuid' => 'UUID',
            'ulid' => 'CHAR(26)',
            'ipAddress' => 'INET',
            'macAddress' => 'MACADDR',
            // No ENUM as a column modifier; a check constraint says the same.
            'enum' => 'VARCHAR(255) CHECK (' . $this->quote($column['name'])
                . ' IN (' . $this->quoteValues($parameters['allowed']) . '))',
            'raw' => $parameters['sql'],
            default => parent::typeFor($column),
        };
    }

    public function compileDropForeign(string $name): string
    {
        return 'DROP CONSTRAINT ' . $this->quote($name);
    }
}
