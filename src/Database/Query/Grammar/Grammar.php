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
        'like', 'not like', 'ilike',
        'in', 'not in',
        'is', 'is not',
        '&', '|', '^', '<<', '>>',
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
        if ($orders = $this->compileOrders($query)) $sql[] = $orders;
        if ($limit = $this->compileLimit($query)) $sql[] = $limit;
        if ($offset = $this->compileOffset($query)) $sql[] = $offset;

        return implode(' ', $sql);
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
        return 'FROM ' . $this->wrapTable($query->getFrom());
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
            $table = $this->wrapTable($join['table']);
            if ($type === 'CROSS') {
                $sql[] = "CROSS JOIN {$table}";
                continue;
            }
            $first = $this->wrap($join['first']);
            $operator = $this->validateOperator($join['operator']);
            $second = $this->wrap($join['second']);
            $sql[] = "{$type} JOIN {$table} ON {$first} {$operator} {$second}";
        }

        return implode(' ', $sql);
    }

    public function compileWheres(QueryBuilder $query): string
    {
        $wheres = $query->getWheres();
        if (empty($wheres)) return '';

        $sql = [];
        foreach ($wheres as $i => $where) {
            $compiled = $this->compileWhere($where);
            $sql[] = ($i === 0) ? $compiled : "{$where['boolean']} {$compiled}";
        }

        return 'WHERE ' . implode(' ', $sql);
    }

    public function compileWhere(array $where): string
    {
        return match ($where['type']) {
            'basic' => $this->wrap($where['column']) . ' ' . $this->validateOperator($where['operator']) . ' ?',
            'in' => $this->wrap($where['column']) . ' IN (' . implode(', ', array_fill(0, count($where['values']), '?')) . ')',
            'not_in' => $this->wrap($where['column']) . ' NOT IN (' . implode(', ', array_fill(0, count($where['values']), '?')) . ')',
            'null' => $this->wrap($where['column']) . ' IS NULL',
            'not_null' => $this->wrap($where['column']) . ' IS NOT NULL',
            'between' => $this->wrap($where['column']) . ' BETWEEN ? AND ?',
            'column' => $this->wrap($where['first']) . ' ' . $this->validateOperator($where['operator']) . ' ' . $this->wrap($where['second']),
            'exists' => "EXISTS ({$where['query']})",
            'not_exists' => "NOT EXISTS ({$where['query']})",
            'raw' => (string) $where['expression'],
            default => '',
        };
    }

    protected function compileGroups(QueryBuilder $query): string
    {
        $groups = $query->getGroups();
        if (empty($groups)) return '';

        return 'GROUP BY ' . implode(', ', array_map([$this, 'wrap'], $groups));
    }

    protected function compileHavings(QueryBuilder $query): string
    {
        $havings = $query->getHavings();
        if (empty($havings)) return '';

        $sql = [];
        foreach ($havings as $having) {
            $sql[] = $this->wrap($having['column']) . ' ' . $this->validateOperator($having['operator']) . ' ?';
        }

        return 'HAVING ' . implode(' AND ', $sql);
    }

    protected function compileOrders(QueryBuilder $query): string
    {
        $orders = $query->getOrders();
        if (empty($orders)) return '';

        $compiled = array_map(function ($o) {
            if ($o instanceof RawExpression) {
                return (string) $o;
            }
            return $this->wrap($o['column']) . ' ' . $this->validateDirection($o['direction']);
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

        if (stripos($value, ' as ') !== false) {
            $parts = preg_split('/\s+as\s+/i', $value, 2);
            $result = $this->wrap($parts[0]) . ' AS ' . $this->wrapSegment($parts[1]);
            return $this->wrapCache[$value] = $result;
        }

        if (str_contains($value, '.')) {
            $segments = explode('.', $value);
            $result = implode('.', array_map(function ($s) {
                return $s === '*' ? '*' : $this->wrapSegment($s);
            }, $segments));
            return $this->wrapCache[$value] = $result;
        }

        return $this->wrapCache[$value] = $this->wrapSegment($value);
    }

    public function wrapTable(string $table): string
    {
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

    protected function validateDirection(string $direction): string
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
