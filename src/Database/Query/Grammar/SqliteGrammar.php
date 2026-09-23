<?php

namespace Nitro\Database\Query\Grammar;

/**
 * SQLite query grammar. Query compilation (select/insert/update/delete) is
 * standard enough that the base Grammar handles it; what differs is schema
 * introspection — SQLite has no information_schema, so these read sqlite_master
 * and the pragma_* table-valued functions instead. Column aliases mirror the
 * base (MySQL) grammar so callers see the same shape.
 */
class SqliteGrammar extends Grammar
{
    /**
     * SQLite accepts backticks for MySQL's sake, but the double quote is the
     * standard form and the one its own tooling emits.
     */
    protected string $identifierQuote = '"';

    public function compileTables(): string
    {
        return "SELECT name AS table_name
                FROM sqlite_master
                WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
                ORDER BY name";
    }

    public function compileViews(): string
    {
        return "SELECT name AS table_name, sql AS view_definition
                FROM sqlite_master
                WHERE type = 'view'
                ORDER BY name";
    }

    public function compileColumnListing(): string
    {
        return "SELECT name AS column_name FROM pragma_table_info(?)";
    }

    public function compileSchemaColumns(): string
    {
        return "SELECT name AS column_name,
                       type AS data_type,
                       type AS column_type,
                       CASE WHEN \"notnull\" = 0 THEN 'YES' ELSE 'NO' END AS is_nullable,
                       dflt_value AS column_default
                FROM pragma_table_info(?)";
    }

    public function compileIndexes(): string
    {
        return "SELECT name AS index_name
                FROM sqlite_master
                WHERE type = 'index' AND tbl_name = ?
                ORDER BY name";
    }

    public function compileForeignKeys(): string
    {
        return "SELECT \"from\" AS column_name,
                       \"table\" AS referenced_table_name,
                       \"to\" AS referenced_column_name
                FROM pragma_foreign_key_list(?)";
    }

    public function compileHasTable(): string
    {
        return "SELECT COUNT(*) AS count
                FROM sqlite_master
                WHERE type = 'table' AND name = ?";
    }

    public function compileHasColumn(): string
    {
        return "SELECT COUNT(*) AS count FROM pragma_table_info(?) WHERE name = ?";
    }

    /**
     * SQLite has no row-level lock clause, and needs none: a write transaction
     * takes a lock over the whole database, so only one writer is ever inside
     * one at a time. The transaction the caller is already holding IS the lock
     * that FOR UPDATE would ask MySQL for.
     *
     * Returning an empty string rather than throwing is what lets the same
     * lockForUpdate() code run on SQLite locally and MySQL in production, which
     * is the arrangement most applications actually develop under.
     */
    public function compileLock(string $type): string
    {
        return '';
    }

    /**
     * SQLite does not accept a parenthesised SELECT as a union term, so each
     * side is enclosed as a subquery instead.
     */
    protected function wrapUnion(string $sql): string
    {
        return "SELECT * FROM ({$sql})";
    }

    /**
     * SQLite has no DATE() over a DATETIME string in the MySQL sense; date()
     * is the equivalent, and it also copes with the ISO strings this framework
     * writes.
     */
    public function compileDate(string $wrappedColumn): string
    {
        return "strftime('%Y-%m-%d', {$wrappedColumn})";
    }

    /**
     * strftime() answers with text, so the bound value is cast to text too.
     * SQLite compares across storage classes by class before value, and an
     * integer year would otherwise never equal the text one.
     */
    public function compileDateValue(): string
    {
        return 'cast(? as text)';
    }

    /**
     * SQLite's json_extract() already returns an unquoted scalar, so there is
     * nothing to unwrap around it.
     */
    protected function wrapJsonSelector(string $value): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($value);

        return "json_extract({$field}{$path})";
    }

    /**
     * SQLite has no json_contains(); json_each() unrolls the array into rows,
     * and the test becomes whether any of them is the value.
     */
    public function compileJsonContains(string $column): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return "exists (select 1 from json_each({$field}{$path}) where json_each.value IS ?)";
    }

    public function compileJsonContainsKey(string $column, bool $not = false): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return "json_type({$field}{$path}) IS " . ($not ? 'NULL' : 'NOT NULL');
    }

    public function compileJsonLength(string $column, string $operator): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return "json_array_length({$field}{$path}) " . $this->validateOperator($operator) . ' ?';
    }

    /**
     * Nor json_overlaps(): the same unrolling, asking whether any element of
     * the column appears in the list bound alongside it.
     */
    public function compileJsonOverlaps(string $column): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return "exists (select 1 from json_each({$field}{$path})"
            . ' where json_each.value IN (select value from json_each(?)))';
    }

    /** SQLite compares the values themselves, not their JSON encoding. */
    public function prepareJsonContainsBinding(mixed $value): mixed
    {
        return is_scalar($value) || $value === null
            ? $value
            : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * SQLite spells the ignore as a conflict resolution on the INSERT itself.
     *
     * @param array<mixed> $values
     */
    public function compileInsertOrIgnore(\Nitro\Database\Query\QueryBuilder $query, array $values): string
    {
        return preg_replace('/^INSERT/', 'INSERT OR IGNORE', $this->compileInsert($query, $values), 1);
    }

    /**
     * SQLite has one index hint, and it only forces a choice; there is no
     * syntax for ignoring an index, so that request is dropped.
     */
    protected function compileIndexHint(\Nitro\Database\Query\QueryBuilder $query): string
    {
        $hint = $query->getIndexHint();

        if ($hint === null || $hint['type'] === 'ignore') {
            return '';
        }

        return 'INDEXED BY ' . $this->wrap($hint['index']);
    }

    /**
     * SQLite has no YEAR()/MONTH()/DAY(); strftime() supplies all of them, and
     * returns a zero-padded string — which is why the builder pads the value
     * it binds for a month or a day.
     */
    public function compileDatePart(string $part, string $wrappedColumn): string
    {
        return match ($part) {
            'date' => $this->compileDate($wrappedColumn),
            'year' => "strftime('%Y', {$wrappedColumn})",
            'month' => "strftime('%m', {$wrappedColumn})",
            'day' => "strftime('%d', {$wrappedColumn})",
            'time' => "strftime('%H:%M:%S', {$wrappedColumn})",
            default => parent::compileDatePart($part, $wrappedColumn),
        };
    }

    /**
     * SQLite's LIKE is case-insensitive for ASCII and cannot be told
     * otherwise per-query. GLOB is the case-sensitive matcher, so a
     * case-sensitive request compiles to that instead and the pattern is
     * translated in {@see prepareLikeBinding()}.
     */
    public function compileLike(string $wrappedColumn, bool $caseSensitive, bool $not): string
    {
        if (! $caseSensitive) {
            return parent::compileLike($wrappedColumn, false, $not);
        }

        return $wrappedColumn . ($not ? ' NOT GLOB ?' : ' GLOB ?');
    }

    /**
     * Translate a LIKE pattern into a GLOB one: % becomes *, _ becomes ?, and
     * GLOB's own metacharacters are bracketed so they match literally.
     */
    public function prepareLikeBinding(string $value, bool $caseSensitive): string
    {
        if (! $caseSensitive) {
            return $value;
        }

        return str_replace(
            ['*', '?', '[', ']', '%', '_'],
            ['[*]', '[?]', '[[]', '[]]', '*', '?'],
            $value
        );
    }
}
