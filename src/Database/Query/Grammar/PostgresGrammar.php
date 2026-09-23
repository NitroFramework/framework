<?php

namespace Nitro\Database\Query\Grammar;

use Nitro\Database\Query\QueryBuilder;

/**
 * PostgreSQL query grammar.
 *
 * Postgres differs from the base (MySQL) grammar in four places that matter:
 * identifiers are double quoted, JSON is reached with the -> and ->> operators
 * rather than json_extract(), conflicts are resolved with ON CONFLICT instead
 * of MySQL's IGNORE and ON DUPLICATE KEY, and a case-insensitive LIKE is a
 * distinct operator rather than a property of the collation.
 */
class PostgresGrammar extends Grammar
{
    /** Postgres folds unquoted identifiers to lower case; quoting preserves them. */
    protected string $identifierQuote = '"';

    // ─── Conflicts ────────────────────────────────────────

    /**
     * @param array<mixed> $values
     */
    public function compileInsertOrIgnore(QueryBuilder $query, array $values): string
    {
        return $this->compileInsert($query, $values) . ' ON CONFLICT DO NOTHING';
    }

    /**
     * @param array<mixed>       $values
     * @param array<int, string> $uniqueBy
     * @param array<mixed>       $update
     */
    public function compileUpsert(QueryBuilder $query, array $values, array $uniqueBy, array $update): string
    {
        $conflict = implode(', ', array_map([$this, 'wrap'], $uniqueBy));

        $assignments = [];

        foreach ($update as $key => $value) {
            // A list names the columns to take from the row that collided;
            // a map states the value to write instead.
            $assignments[] = is_int($key)
                ? $this->wrap($value) . ' = excluded.' . $this->wrap($value)
                : $this->wrap($key) . ' = ?';
        }

        return $this->compileInsert($query, $values)
            . " ON CONFLICT ({$conflict}) DO UPDATE SET " . implode(', ', $assignments);
    }

    /**
     * Postgres leaves a truncated table's sequences where they were, so the
     * identity is restarted to match what MySQL and SQLite do.
     */
    public function compileTruncate(string $table): string
    {
        return 'TRUNCATE ' . $this->wrapTable($table) . ' RESTART IDENTITY';
    }

    // ─── Locking, ordering, matching ──────────────────────

    public function compileLock(string $type): string
    {
        return $type === 'share' ? 'FOR SHARE' : 'FOR UPDATE';
    }

    public function compileRandom(string $seed = ''): string
    {
        return 'RANDOM()';
    }

    /** Postgres has no index hint; the planner is not steered from the query. */
    protected function compileIndexHint(QueryBuilder $query): string
    {
        return '';
    }

    /**
     * ILIKE is the case-insensitive match, so neither side needs lowering and
     * an index on the column still applies.
     */
    public function compileLike(string $wrappedColumn, bool $caseSensitive, bool $not): string
    {
        // Cast to text, because Postgres has no LIKE for a non-text column and
        // matching a pattern against an integer or an enum is otherwise an error.
        return $wrappedColumn . '::text'
            . ($not ? ' NOT ' : ' ') . ($caseSensitive ? 'LIKE' : 'ILIKE') . ' ?';
    }

    /**
     * @param array<int, string>   $columns
     * @param array<string, mixed> $options
     */
    public function compileFullText(array $columns, array $options): string
    {
        $language = $options['language'] ?? 'english';

        if (preg_match('/^[a-z_]+$/', $language) !== 1) {
            $language = 'english';
        }

        $vectors = implode(' || ', array_map(
            fn (string $column): string => "to_tsvector('{$language}', " . $this->wrap($column) . ')',
            $columns,
        ));

        $mode = match ($options['mode'] ?? null) {
            'phrase' => 'phraseto_tsquery',
            'websearch' => 'websearch_to_tsquery',
            default => 'plainto_tsquery',
        };

        return "({$vectors}) @@ {$mode}('{$language}', ?)";
    }

    // ─── Dates ────────────────────────────────────────────

    public function compileDate(string $wrappedColumn): string
    {
        return "{$wrappedColumn}::date";
    }

    public function compileDatePart(string $part, string $wrappedColumn): string
    {
        return match ($part) {
            'date' => $this->compileDate($wrappedColumn),
            'year' => "EXTRACT(YEAR FROM {$wrappedColumn})",
            'month' => "EXTRACT(MONTH FROM {$wrappedColumn})",
            'day' => "EXTRACT(DAY FROM {$wrappedColumn})",
            'time' => "{$wrappedColumn}::time",
            default => parent::compileDatePart($part, $wrappedColumn),
        };
    }

    // ─── JSON ─────────────────────────────────────────────

    /**
     * Reach a value as text, with ->> on the last step and -> on the rest.
     *
     * An integer segment indexes an array, and Postgres tells the two apart by
     * the quoting: options->2 is the third element, options->'2' is the key.
     */
    protected function wrapJsonSelector(string $value): string
    {
        $path = explode('->', $value);
        $field = $this->wrap(array_shift($path));

        $segments = array_map([$this, 'wrapJsonPathSegment'], $path);
        $attribute = array_pop($segments);

        return $segments === []
            ? "{$field}->>{$attribute}"
            : "{$field}->" . implode('->', $segments) . "->>{$attribute}";
    }

    /**
     * The same path, stopping at -> so the result stays JSON rather than text.
     *
     * Containment and length work on jsonb, which text would not satisfy.
     */
    protected function wrapJsonObjectSelector(string $value): string
    {
        $path = explode('->', $value);
        $field = $this->wrap(array_shift($path));

        if ($path === []) {
            return $field;
        }

        return $field . '->' . implode('->', array_map([$this, 'wrapJsonPathSegment'], $path));
    }

    /** A path segment: bare when it indexes an array, quoted when it names a key. */
    protected function wrapJsonPathSegment(string $segment): string
    {
        return filter_var($segment, FILTER_VALIDATE_INT) !== false
            ? $segment
            : "'" . str_replace("'", "''", $segment) . "'";
    }

    /**
     * A boolean is compared as jsonb, not as text.
     *
     * ->> would answer with the string 'true', which Postgres refuses to
     * compare against a boolean rather than coercing as MySQL would.
     */
    protected function wrapJsonBooleanSelector(string $value): string
    {
        return '(' . $this->wrapJsonObjectSelector($value) . ')::jsonb';
    }

    protected function wrapJsonBooleanValue(string $value): string
    {
        return "'{$value}'::jsonb";
    }

    public function compileJsonContains(string $column): string
    {
        return '(' . $this->wrapJsonObjectSelector($column) . ')::jsonb @> ?';
    }

    public function compileJsonContainsKey(string $column, bool $not = false): string
    {
        $path = explode('->', $column);
        $key = array_pop($path);

        $target = $this->wrapJsonObjectSelector(implode('->', $path));

        return ($not ? 'NOT ' : '') . "({$target})::jsonb ?? " . $this->wrapJsonPathSegment($key);
    }

    public function compileJsonLength(string $column, string $operator): string
    {
        return 'jsonb_array_length((' . $this->wrapJsonObjectSelector($column) . ')::jsonb) '
            . $this->validateOperator($operator) . ' ?';
    }

    public function compileJsonOverlaps(string $column): string
    {
        return '(' . $this->wrapJsonObjectSelector($column) . ')::jsonb ?| ?';
    }

    // ─── Schema introspection ─────────────────────────────

    public function compileTables(): string
    {
        return "SELECT c.relname AS table_name, NULL AS engine,
                       NULL AS table_collation, obj_description(c.oid) AS table_comment
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE c.relkind = 'r' AND n.nspname = CURRENT_SCHEMA()
                ORDER BY c.relname";
    }

    public function compileViews(): string
    {
        return "SELECT table_name, view_definition
                FROM information_schema.views
                WHERE table_schema = CURRENT_SCHEMA()
                ORDER BY table_name";
    }

    public function compileColumnListing(): string
    {
        return "SELECT column_name
                FROM information_schema.columns
                WHERE table_schema = CURRENT_SCHEMA() AND table_name = ?
                ORDER BY ordinal_position";
    }

    /**
     * Aliased to the base grammar's shape, so a caller reads the same keys
     * whichever engine answered.
     */
    public function compileSchemaColumns(): string
    {
        return "SELECT c.column_name, c.data_type,
                       format_type(a.atttypid, a.atttypmod) AS column_type,
                       c.is_nullable, c.column_default,
                       CASE WHEN i.indisprimary THEN 'PRI' ELSE '' END AS column_key,
                       CASE WHEN c.is_identity = 'YES'
                                 OR c.column_default LIKE 'nextval(%'
                            THEN 'auto_increment' ELSE '' END AS extra,
                       c.character_maximum_length, c.numeric_precision, c.numeric_scale,
                       col_description(a.attrelid, a.attnum) AS column_comment
                FROM information_schema.columns c
                JOIN pg_class cl ON cl.relname = c.table_name
                JOIN pg_namespace n ON n.oid = cl.relnamespace AND n.nspname = c.table_schema
                JOIN pg_attribute a ON a.attrelid = cl.oid AND a.attname = c.column_name
                LEFT JOIN pg_index i ON i.indrelid = cl.oid
                                    AND a.attnum = ANY (i.indkey) AND i.indisprimary
                WHERE c.table_schema = CURRENT_SCHEMA() AND c.table_name = ?
                ORDER BY c.ordinal_position";
    }

    public function compileIndexes(): string
    {
        return "SELECT i.relname AS index_name, a.attname AS column_name,
                       CASE WHEN ix.indisunique THEN 0 ELSE 1 END AS non_unique,
                       am.amname AS index_type,
                       array_position(ix.indkey, a.attnum) + 1 AS seq_in_index
                FROM pg_class t
                JOIN pg_namespace n ON n.oid = t.relnamespace
                JOIN pg_index ix ON t.oid = ix.indrelid
                JOIN pg_class i ON i.oid = ix.indexrelid
                JOIN pg_am am ON am.oid = i.relam
                JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY (ix.indkey)
                WHERE n.nspname = CURRENT_SCHEMA() AND t.relname = ?
                ORDER BY i.relname, seq_in_index";
    }

    public function compileForeignKeys(): string
    {
        return "SELECT tc.constraint_name, kcu.column_name,
                       ccu.table_name AS referenced_table_name,
                       ccu.column_name AS referenced_column_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                  ON kcu.constraint_name = tc.constraint_name
                 AND kcu.table_schema = tc.table_schema
                JOIN information_schema.constraint_column_usage ccu
                  ON ccu.constraint_name = tc.constraint_name
                 AND ccu.table_schema = tc.table_schema
                WHERE tc.constraint_type = 'FOREIGN KEY'
                  AND tc.table_schema = CURRENT_SCHEMA() AND tc.table_name = ?
                ORDER BY tc.constraint_name";
    }

    public function compileHasTable(): string
    {
        return "SELECT COUNT(*) as count
                FROM information_schema.tables
                WHERE table_schema = CURRENT_SCHEMA() AND table_name = ?";
    }

    public function compileHasColumn(): string
    {
        return "SELECT COUNT(*) as count
                FROM information_schema.columns
                WHERE table_schema = CURRENT_SCHEMA() AND table_name = ? AND column_name = ?";
    }
}
