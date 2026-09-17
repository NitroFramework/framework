<?php

namespace Nitro\Database\Query\Grammar;

use InvalidArgumentException;
use Nitro\Database\Query\QueryBuilder;
use Nitro\Database\Query\RawExpression;

/**
 * Base SQL grammar — compiles query-builder state into SQL.
 */
class Grammar
{
    protected const VALID_OPERATORS = [
        '=', '!=', '<>', '<', '<=', '>', '>=',
        'like', 'not like', 'like binary', 'not like binary', 'ilike', 'not ilike',
        'glob', 'not glob',
        'rlike', 'not rlike', 'regexp', 'not regexp',
        'in', 'not in',
        'is', 'is not',
        '&', '|', '^', '<<', '>>', '&~',
        '<=>',
    ];

    protected const VALID_AGGREGATE_FUNCTIONS = [
        'COUNT', 'SUM', 'AVG', 'MIN', 'MAX',
    ];

    /**
     * Memoized identifier wrap. wrap() is called once per column reference
     * per query — the same set of identifiers ('users.id', 'posts.title',
     * 'id', '*') recur thousands of times per request. Keying by raw input
     * skips the regex + str_contains chain on every repeat.
     */
    private array $wrapCache = [];

    /** Memoized full-table wrap. Same logic, table-flavor. */
    private array $tableWrapCache = [];

    /**
     * Validated-operator cache. Operators are short and highly repetitive;
     * we want to skip strtolower/strtoupper/in_array on every where clause.
     */
    private array $operatorCache = [];

    // ─── Query Compilation ────────────────────────────────

    public function compileSelect(QueryBuilder $query): string
    {
        $sql = [];

        $sql[] = $this->compileColumns($query);
        $sql[] = $this->compileFrom($query);

        if ($joins = $this->compileJoins($query)) $sql[] = $joins;
        if ($wheres = $this->compileWheres($query)) $sql[] = $wheres;
        if ($groups = $this->compileGroups($query)) $sql[] = $groups;
        if ($havings = $this->compileHavings($query)) $sql[] = $havings;

        // Before the ordering and the limit, which apply to the combined
        // result rather than to the query that started it.
        if ($unions = $this->compileUnions($query)) $sql[] = $unions;

        if ($orders = $this->compileOrders($query)) $sql[] = $orders;
        if ($limit = $this->compileLimit($query)) $sql[] = $limit;
        if ($offset = $this->compileOffset($query)) $sql[] = $offset;

        // Last, because FOR UPDATE closes the statement.
        if (($lock = $query->getLock()) !== null && ($clause = $this->compileLock($lock)) !== '') {
            $sql[] = $clause;
        }

        return implode(' ', $sql);
    }

    /**
     * Wrap a datetime column so that it compares as a date.
     *
     * Per engine, because comparing a DATETIME to '2026-09-13' with a plain =
     * matches only the rows stored at exactly midnight.
     */
    public function compileDate(string $wrappedColumn): string
    {
        return "DATE({$wrappedColumn})";
    }

    public function compileInsert(QueryBuilder $query, array $values): string
    {
        $table = $this->wrapTable($query->getFrom());

        // MySQL doesn't support 'INSERT INTO t DEFAULT VALUES' — it needs
        // '() VALUES ()'. Drivers that DO support the standard syntax
        // override this in a subclass.
        if (empty($values)) {
            return "INSERT INTO {$table} () VALUES ()";
        }

        if (!is_array(reset($values))) {
            $values = [$values];
        }

        $columns = implode(', ', array_map([$this, 'wrap'], array_keys($values[0])));
        $placeholders = implode(', ', array_map(static function ($record) {
            return '(' . implode(', ', array_fill(0, count($record), '?')) . ')';
        }, $values));

        return "INSERT INTO {$table} ({$columns}) VALUES {$placeholders}";
    }

    public function compileInsertGetId(QueryBuilder $query, array $values): string
    {
        return $this->compileInsert($query, $values);
    }

    /**
     * An insert that passes over the rows which collide with an existing key.
     *
     * @param array<mixed> $values
     */
    public function compileInsertOrIgnore(QueryBuilder $query, array $values): string
    {
        return preg_replace('/^INSERT/', 'INSERT IGNORE', $this->compileInsert($query, $values), 1);
    }

    /**
     * An insert whose rows come from a select.
     *
     * @param array<int, string> $columns
     */
    public function compileInsertUsing(QueryBuilder $query, array $columns, string $select): string
    {
        $table = $this->wrapTable($query->getFrom());

        if ($columns === []) {
            return "INSERT INTO {$table} {$select}";
        }

        $wrapped = implode(', ', array_map([$this, 'wrap'], $columns));

        return "INSERT INTO {$table} ({$wrapped}) {$select}";
    }

    public function compileUpdate(QueryBuilder $query, array $values): string
    {
        $table = $this->wrapTable($query->getFrom());

        $columns = implode(', ', array_map(function ($col) use ($values) {
            $wrapped = $this->wrap($col);
            if ($values[$col] instanceof RawExpression) {
                return "{$wrapped} = {$values[$col]}";
            }
            return "{$wrapped} = ?";
        }, array_keys($values)));

        $sql = "UPDATE {$table} SET {$columns}";

        if ($wheres = $this->compileWheres($query)) {
            $sql .= ' ' . $wheres;
        }

        return $sql;
    }

    public function compileDelete(QueryBuilder $query): string
    {
        $table = $this->wrapTable($query->getFrom());
        $sql = "DELETE FROM {$table}";

        if ($wheres = $this->compileWheres($query)) {
            $sql .= ' ' . $wheres;
        }

        return $sql;
    }

    /**
     * EXISTS subquery — uses 'SELECT 1' inside instead of replaying the
     * full column list. MySQL can short-circuit on the first matching row
     * once it sees a constant projection, and we skip transferring data
     * we never read.
     */
    public function compileExists(QueryBuilder $query): string
    {
        $inner = $this->compileExistsInner($query);
        return "SELECT EXISTS({$inner}) AS `exists`";
    }

    /**
     * Inner select for EXISTS — strips columns to SELECT 1 and drops
     * ordering (which EXISTS ignores). Limit/offset stay because they
     * still affect what's "exists-able".
     */
    protected function compileExistsInner(QueryBuilder $query): string
    {
        $sql = ['SELECT 1', $this->compileFrom($query)];

        if ($joins = $this->compileJoins($query))   $sql[] = $joins;
        if ($wheres = $this->compileWheres($query)) $sql[] = $wheres;
        if ($groups = $this->compileGroups($query)) $sql[] = $groups;
        if ($havings = $this->compileHavings($query)) $sql[] = $havings;
        if ($limit = $this->compileLimit($query))   $sql[] = $limit;
        if ($offset = $this->compileOffset($query)) $sql[] = $offset;

        return implode(' ', $sql);
    }

    public function compileAggregate(QueryBuilder $query, string $function, string $column): string
    {
        $function = $this->validateAggregateFunction($function);
        $column = $column === '*' ? '*' : $this->wrap($column);
        $table = $this->wrapTable($query->getFrom());
        $sql = "SELECT {$function}({$column}) AS aggregate FROM {$table}";

        if ($joins = $this->compileJoins($query)) $sql .= ' ' . $joins;
        if ($wheres = $this->compileWheres($query)) $sql .= ' ' . $wheres;
        if ($groups = $this->compileGroups($query)) $sql .= ' ' . $groups;
        if ($havings = $this->compileHavings($query)) $sql .= ' ' . $havings;

        return $sql;
    }

    // ─── Clause Compilation ───────────────────────────────

    protected function compileColumns(QueryBuilder $query): string
    {
        $columns = $query->getColumns();

        if (empty($columns) || $columns === ['*']) {
            return $query->isDistinct() ? 'SELECT DISTINCT *' : 'SELECT *';
        }

        $compiled = array_map(function ($col) {
            if ($col instanceof RawExpression) {
                return (string) $col;
            }
            return $this->wrap($col);
        }, $columns);

        $distinct = $query->isDistinct() ? 'DISTINCT ' : '';

        return 'SELECT ' . $distinct . implode(', ', $compiled);
    }

    protected function compileFrom(QueryBuilder $query): string
    {
        $sql = 'FROM ' . $this->wrapTable($query->getFrom());

        if ($hint = $this->compileIndexHint($query)) {
            $sql .= ' ' . $hint;
        }

        return $sql;
    }

    /**
     * Compile an index hint, where the engine has syntax for one.
     *
     * Ignored by default: a hint changes how a query runs, never what it
     * returns, so a grammar that cannot express one drops it rather than
     * refusing the query.
     */
    protected function compileIndexHint(QueryBuilder $query): string
    {
        return '';
    }

    /**
     * Compile the queries appended with UNION.
     *
     * Each keeps its own WHERE; only the outer ordering and limit apply to
     * the combined result.
     */
    protected function compileUnions(QueryBuilder $query): string
    {
        $unions = $query->getUnions();

        if ($unions === []) {
            return '';
        }

        $sql = [];

        foreach ($unions as $union) {
            $sql[] = ($union['all'] ? 'UNION ALL ' : 'UNION ') . $union['query']->toSql();
        }

        return implode(' ', $sql);
    }

    /** The engine's random-ordering function. */
    public function compileRandom(string $seed = ''): string
    {
        return 'RANDOM()';
    }

    // ─── JSON ─────────────────────────────────────────────

    /**
     * Compile a reference into a JSON column: options->notifications->email.
     */
    protected function wrapJsonSelector(string $value): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($value);

        // Unquoted, so a string inside the document compares against a bound
        // string rather than against its quoted JSON form.
        return "json_unquote(json_extract({$field}{$path}))";
    }

    /**
     * Split a JSON reference into the wrapped column and its path argument.
     *
     * @return array{0: string, 1: string} The path is empty when none was given.
     */
    protected function wrapJsonFieldAndPath(string $value): array
    {
        $parts = explode('->', $value);
        $field = $this->wrap(array_shift($parts));

        if ($parts === []) {
            return [$field, ''];
        }

        $path = '$' . implode('', array_map(
            static fn (string $segment): string => is_numeric($segment)
                ? "[{$segment}]"
                : '."' . str_replace('"', '', $segment) . '"',
            $parts
        ));

        return [$field, ", '{$path}'"];
    }

    public function compileJsonContains(string $column): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return "json_contains({$field}, ?{$path})";
    }

    public function compileJsonContainsKey(string $column, bool $not = false): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        if ($path === '') {
            return "json_type({$field}) IS " . ($not ? 'NULL' : 'NOT NULL');
        }

        return ($not ? 'NOT ' : '') . 'ifnull(json_contains_path(' . $field . ", 'one'" . $path . '), 0)';
    }

    public function compileJsonLength(string $column, string $operator): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return "json_length({$field}{$path}) " . $this->validateOperator($operator) . ' ?';
    }

    public function compileJsonOverlaps(string $column): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        $target = $path === '' ? $field : "json_extract({$field}{$path})";

        return "json_overlaps({$target}, ?)";
    }

    /**
     * The value bound for a JSON containment test.
     *
     * The engine compares JSON against JSON, so a PHP value is encoded before
     * it is bound — 'en' has to arrive as '"en"' to match an element of
     * ["en", "fr"].
     */
    public function prepareJsonContainsBinding(mixed $value): mixed
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function prepareJsonOverlapsBinding(mixed $value): mixed
    {
        return json_encode(
            is_array($value) ? array_values($value) : [$value],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * Compile a full-text search.
     *
     * @param array<int, string>   $columns
     * @param array<string, mixed> $options
     */
    public function compileFullText(array $columns, array $options): string
    {
        throw new \RuntimeException('This database engine does not support full text search.');
    }

    protected function compileJoins(QueryBuilder $query): string
    {
        $joins = $query->getJoins();
        if (empty($joins)) return '';

        $sql = [];

        foreach ($joins as $join) {
            $type = strtoupper($join['type']);

            if (!in_array($type, ['INNER', 'LEFT', 'RIGHT', 'CROSS', 'FULL'], true)) {
                throw new InvalidArgumentException("Invalid join type: {$join['type']}");
            }

            $table = $join['table'] instanceof RawExpression
                ? (string) $join['table']
                : $this->wrapTable($join['table']);

            // A join's ON is a boolean expression like any other, so it
            // compiles through the same path as a WHERE — which is what lets a
            // join carry an OR, a nested group or a value comparison.
            $conditions = $this->compileWhereGroup($join['wheres']);

            $sql[] = $conditions === ''
                ? "{$type} JOIN {$table}"
                : "{$type} JOIN {$table} ON {$conditions}";
        }

        return implode(' ', $sql);
    }

    public function compileWheres(QueryBuilder $query): string
    {
        $wheres = $query->getWheres();

        if (empty($wheres)) {
            return '';
        }

        return 'WHERE ' . $this->compileWhereGroup($wheres);
    }

    /**
     * Compile a list of where clauses into one boolean expression, without the
     * leading WHERE.
     *
     * The first clause never carries its boolean — "WHERE AND x = ?" is not
     * SQL — which is also what makes a nested group safe to compile through
     * here: the group's own boolean belongs to the clause holding it, not to
     * the first clause inside it.
     *
     * @param array<int, array<string, mixed>> $wheres
     */
    public function compileWhereGroup(array $wheres): string
    {
        $sql = [];

        foreach ($wheres as $where) {
            $compiled = $this->compileWhere($where);

            if ($compiled === '') {
                continue;
            }

            $sql[] = ($sql === []) ? $compiled : "{$where['boolean']} {$compiled}";
        }

        return implode(' ', $sql);
    }

    public function compileWhere(array $where): string
    {
        return match ($where['type']) {
            'basic' => $this->wrap($where['column']) . ' ' . $this->validateOperator($where['operator']) . ' ?',
            'in' => $this->wrap($where['column']) . ' IN (' . implode(', ', array_fill(0, count($where['values']), '?')) . ')',
            'not_in' => $this->wrap($where['column']) . ' NOT IN (' . implode(', ', array_fill(0, count($where['values']), '?')) . ')',
            'in_raw' => $this->wrap($where['column']) . ' IN (' . implode(', ', $where['values']) . ')',
            'not_in_raw' => $this->wrap($where['column']) . ' NOT IN (' . implode(', ', $where['values']) . ')',
            'null' => $this->wrap($where['column']) . ' IS NULL',
            'not_null' => $this->wrap($where['column']) . ' IS NOT NULL',
            'between' => $this->wrap($where['column']) . ' BETWEEN ? AND ?',
            'not_between' => $this->wrap($where['column']) . ' NOT BETWEEN ? AND ?',
            'between_columns' => $this->wrap($where['column']) . ' BETWEEN '
                . $this->wrap($where['values'][0]) . ' AND ' . $this->wrap($where['values'][1]),
            'not_between_columns' => $this->wrap($where['column']) . ' NOT BETWEEN '
                . $this->wrap($where['values'][0]) . ' AND ' . $this->wrap($where['values'][1]),
            'column' => $this->wrap($where['first']) . ' ' . $this->validateOperator($where['operator']) . ' ' . $this->wrap($where['second']),
            'date' => $this->compileDatePart($where['part'], $this->wrap($where['column']))
                . ' ' . $this->validateOperator($where['operator']) . ' ?',
            'like' => $this->compileLike($this->wrap($where['column']), $where['caseSensitive'], $where['not']),
            'row_values' => '(' . implode(', ', array_map([$this, 'wrap'], $where['columns'])) . ') '
                . $this->validateOperator($where['operator'])
                . ' (' . implode(', ', array_fill(0, count($where['values']), '?')) . ')',
            'nested' => '(' . $this->compileWhereGroup($where['wheres']) . ')',
            'not_nested' => 'NOT (' . $this->compileWhereGroup($where['wheres']) . ')',
            'json_contains' => ($where['not'] ? 'NOT ' : '') . $this->compileJsonContains($where['column']),
            'json_contains_key' => $this->compileJsonContainsKey($where['column'], $where['not']),
            'json_length' => $this->compileJsonLength($where['column'], $where['operator']),
            'json_overlaps' => ($where['not'] ? 'NOT ' : '') . $this->compileJsonOverlaps($where['column']),
            'fulltext' => $this->compileFullText($where['columns'], $where['options']),
            'exists' => "EXISTS ({$where['query']})",
            'not_exists' => "NOT EXISTS ({$where['query']})",
            'sub' => $this->wrap($where['column']) . ' ' . $this->validateOperator($where['operator']) . " ({$where['query']})",
            'in_sub' => $this->wrap($where['column']) . " IN ({$where['query']})",
            'not_in_sub' => $this->wrap($where['column']) . " NOT IN ({$where['query']})",
            'raw' => (string) $where['expression'],
            default => '',
        };
    }

    /**
     * Wrap a datetime column so it compares by one of its parts.
     *
     * Per engine, because the functions differ and comparing a DATETIME to
     * '2026-09-13' with a plain = matches only the rows stored at midnight.
     */
    public function compileDatePart(string $part, string $wrappedColumn): string
    {
        return match ($part) {
            'date' => $this->compileDate($wrappedColumn),
            'year' => "YEAR({$wrappedColumn})",
            'month' => "MONTH({$wrappedColumn})",
            'day' => "DAY({$wrappedColumn})",
            'time' => "TIME({$wrappedColumn})",
            default => throw new InvalidArgumentException("Unknown date part: {$part}"),
        };
    }

    /**
     * Compile a LIKE comparison.
     *
     * Case sensitivity is not something SQL carries as a flag — engines decide
     * it by collation — so an engine that cannot honour the request says so
     * rather than quietly matching more rows than were asked for.
     */
    public function compileLike(string $wrappedColumn, bool $caseSensitive, bool $not): string
    {
        if ($caseSensitive) {
            throw new \RuntimeException('This database engine does not support case sensitive LIKE.');
        }

        return $wrappedColumn . ($not ? ' NOT LIKE ?' : ' LIKE ?');
    }

    /**
     * Adjust a LIKE pattern to suit the form {@see compileLike()} emitted.
     *
     * A grammar that reaches for a different operator to get case sensitivity
     * translates the pattern here, so callers keep writing LIKE wildcards.
     */
    public function prepareLikeBinding(string $value, bool $caseSensitive): string
    {
        return $value;
    }

    protected function compileGroups(QueryBuilder $query): string
    {
        $groups = $query->getGroups();
        if (empty($groups)) return '';

        $compiled = array_map(
            fn ($group): string => $group instanceof RawExpression ? (string) $group : $this->wrap($group),
            $groups
        );

        return 'GROUP BY ' . implode(', ', $compiled);
    }

    protected function compileHavings(QueryBuilder $query): string
    {
        $havings = $query->getHavings();

        if (empty($havings)) {
            return '';
        }

        return 'HAVING ' . $this->compileHavingGroup($havings);
    }

    /**
     * @param array<int, array<string, mixed>> $havings
     */
    public function compileHavingGroup(array $havings): string
    {
        $sql = [];

        foreach ($havings as $having) {
            $compiled = $this->compileHaving($having);

            if ($compiled === '') {
                continue;
            }

            $sql[] = ($sql === []) ? $compiled : "{$having['boolean']} {$compiled}";
        }

        return implode(' ', $sql);
    }

    public function compileHaving(array $having): string
    {
        return match ($having['type']) {
            'basic' => $this->wrap($having['column']) . ' ' . $this->validateOperator($having['operator']) . ' ?',
            'between' => $this->wrap($having['column']) . ' BETWEEN ? AND ?',
            'not_between' => $this->wrap($having['column']) . ' NOT BETWEEN ? AND ?',
            'null' => $this->wrap($having['column']) . ' IS NULL',
            'not_null' => $this->wrap($having['column']) . ' IS NOT NULL',
            'nested' => '(' . $this->compileHavingGroup($having['havings']) . ')',
            'raw' => (string) $having['expression'],
            default => '',
        };
    }

    protected function compileOrders(QueryBuilder $query): string
    {
        $orders = $query->getOrders();
        if (empty($orders)) return '';

        $compiled = array_map(function ($order) {
            if ($order instanceof RawExpression) {
                return (string) $order;
            }
            return $this->wrap($order['column']) . ' ' . $this->validateDirection($order['direction']);
        }, $orders);

        return 'ORDER BY ' . implode(', ', $compiled);
    }

    protected function compileLimit(QueryBuilder $query): string
    {
        $limit = $query->getLimitValue();
        return $limit !== null ? "LIMIT {$limit}" : '';
    }

    protected function compileOffset(QueryBuilder $query): string
    {
        $offset = $query->getOffsetValue();
        return $offset !== null ? "OFFSET {$offset}" : '';
    }

    // ─── Driver-specific (override in subclasses) ─────────

    public function compileUpsert(QueryBuilder $query, array $values, array $uniqueBy, array $update): string
    {
        throw new \RuntimeException('This grammar does not support upsert. Use a driver-specific grammar.');
    }

    public function compileLock(string $type): string
    {
        throw new \RuntimeException('This grammar does not support locking. Use a driver-specific grammar.');
    }

    public function compileTruncate(string $table): string
    {
        return "TRUNCATE TABLE {$this->wrapTable($table)}";
    }

    // ─── Identifier Wrapping ──────────────────────────────

    /**
     * Wrap a column reference in backticks, handling 'as' aliases and
     * 'table.col' segments. Memoized — the same identifier wrapped a
     * second time returns the cached string instead of re-parsing.
     */
    public function wrap(string $value): string
    {
        if (isset($this->wrapCache[$value])) {
            return $this->wrapCache[$value];
        }

        if ($value === '*') {
            return $this->wrapCache[$value] = '*';
        }

        // 'options->theme' addresses a value inside a JSON column, which no
        // amount of quoting turns into a column name — the engine needs its
        // own accessor for it.
        if (str_contains($value, '->')) {
            return $this->wrapCache[$value] = $this->wrapJsonSelector($value);
        }

        if (stripos($value, ' as ') !== false) {
            $parts = preg_split('/\s+as\s+/i', $value, 2);
            $result = $this->wrap($parts[0]) . ' AS ' . $this->wrapSegment($parts[1]);
            return $this->wrapCache[$value] = $result;
        }

        if (str_contains($value, '.')) {
            $segments = explode('.', $value);
            $result = implode('.', array_map(function ($segment) {
                return $segment === '*' ? '*' : $this->wrapSegment($segment);
            }, $segments));
            return $this->wrapCache[$value] = $result;
        }

        return $this->wrapCache[$value] = $this->wrapSegment($value);
    }

    public function wrapTable(string|RawExpression $table): string
    {
        // A raw FROM — a sub-select under an alias, say — is already written
        // the way it must appear, so wrapping it would only break it.
        if ($table instanceof RawExpression) {
            return (string) $table;
        }

        if (isset($this->tableWrapCache[$table])) {
            return $this->tableWrapCache[$table];
        }

        if ($table === '') {
            throw new InvalidArgumentException('Table name cannot be empty.');
        }

        if (stripos($table, ' as ') !== false) {
            $parts = preg_split('/\s+as\s+/i', $table, 2);
            $result = $this->wrapTable($parts[0]) . ' AS ' . $this->wrapSegment($parts[1]);
            return $this->tableWrapCache[$table] = $result;
        }

        if (str_contains($table, '.')) {
            $segments = explode('.', $table);
            $result = implode('.', array_map([$this, 'wrapSegment'], $segments));
            return $this->tableWrapCache[$table] = $result;
        }

        return $this->tableWrapCache[$table] = $this->wrapSegment($table);
    }

    protected function wrapSegment(string $segment): string
    {
        $segment = trim($segment);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment)) {
            throw new InvalidArgumentException("Invalid identifier: {$segment}");
        }
        return '`' . $segment . '`';
    }

    protected function validateOperator(string $operator): string
    {
        if (isset($this->operatorCache[$operator])) {
            return $this->operatorCache[$operator];
        }
        $normalized = strtolower(trim($operator));
        if (!in_array($normalized, self::VALID_OPERATORS, true)) {
            throw new InvalidArgumentException("Invalid SQL operator: {$operator}");
        }
        return $this->operatorCache[$operator] = strtoupper($normalized);
    }

    public function validateDirection(string $direction): string
    {
        $direction = strtoupper(trim($direction));
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException("Invalid order direction: {$direction}");
        }
        return $direction;
    }

    protected function validateAggregateFunction(string $function): string
    {
        $upper = strtoupper(trim($function));
        if (!in_array($upper, self::VALID_AGGREGATE_FUNCTIONS, true)) {
            throw new InvalidArgumentException("Invalid aggregate function: {$function}");
        }
        return $upper;
    }

    // ─── Schema Introspection (override per driver) ───────

    public function compileTables(): string
    {
        return "SELECT table_name, engine, table_collation, table_comment
                FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
                ORDER BY table_name";
    }

    public function compileViews(): string
    {
        return "SELECT table_name, view_definition
                FROM information_schema.views
                WHERE table_schema = DATABASE()
                ORDER BY table_name";
    }

    public function compileColumnListing(): string
    {
        return "SELECT column_name
                FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = ?
                ORDER BY ordinal_position";
    }

    public function compileSchemaColumns(): string
    {
        return "SELECT column_name, data_type, column_type, is_nullable,
                       column_default, column_key, extra, character_maximum_length,
                       numeric_precision, numeric_scale, column_comment
                FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = ?
                ORDER BY ordinal_position";
    }

    public function compileIndexes(): string
    {
        return "SELECT index_name, column_name, non_unique, index_type, seq_in_index
                FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = ?
                ORDER BY index_name, seq_in_index";
    }

    public function compileForeignKeys(): string
    {
        return "SELECT constraint_name, column_name,
                       referenced_table_name, referenced_column_name
                FROM information_schema.key_column_usage
                WHERE table_schema = DATABASE() AND table_name = ?
                  AND referenced_table_name IS NOT NULL
                ORDER BY constraint_name";
    }

    public function compileHasTable(): string
    {
        return "SELECT COUNT(*) as count
                FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_name = ?";
    }

    public function compileHasColumn(): string
    {
        return "SELECT COUNT(*) as count
                FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?";
    }
}
