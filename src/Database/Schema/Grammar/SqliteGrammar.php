<?php

namespace Nitro\Database\Schema\Grammar;

/**
 * SQLite schema grammar.
 *
 * SQLite stores by affinity rather than by declared type, so most of MySQL's
 * spellings are accepted as written and are left alone. What genuinely differs
 * is the generated key, where the auto-incrementing column must be the rowid
 * alias, and where the indexes go — CREATE TABLE will not hold them.
 */
class SqliteGrammar extends Grammar
{
    protected string $tableSuffix = '';

    protected bool $inlineIndexes = false;

    /** No UNSIGNED keyword; the affinity of the declared type is the whole story. */
    protected bool $supportsUnsigned = false;

    protected bool $supportsInlineComment = false;

    /** ALTER TABLE ADD COLUMN takes no position. */
    protected bool $supportsColumnPosition = false;

    /**
     * @param array<string, mixed> $column
     */
    public function typeFor(array $column): string
    {
        $parameters = $column['parameters'] ?? [];

        return match ($column['type']) {
            // INTEGER PRIMARY KEY is the rowid itself; any other spelling,
            // BIGINT included, makes an ordinary column that does not generate.
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            // SQLite has no ENUM and will not take one — a check constraint
            // holds the same set of values, which is what it is for.
            'enum' => 'VARCHAR(255) CHECK (' . $this->quote($column['name'])
                . ' IN (' . $this->quoteValues($parameters['allowed']) . '))',
            default => parent::typeFor($column),
        };
    }

    /** SQLite drops a foreign key only by rebuilding the table; it has no clause. */
    public function compileDropForeign(string $name): string
    {
        throw new \RuntimeException(
            'SQLite cannot drop a foreign key. The table has to be rebuilt without it.'
        );
    }
}
