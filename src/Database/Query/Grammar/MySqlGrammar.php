<?php

namespace Nitro\Database\Query\Grammar;

use Nitro\Database\Query\QueryBuilder;
use Nitro\Database\Query\RawExpression;

/**
 * MySQL-specific SQL grammar.
 */
class MySqlGrammar extends Grammar
{
    /**
     * ON DUPLICATE KEY UPDATE using the row-alias form
     * ('VALUES ... AS new') so it works on MySQL 8.0.19+ AND 8.0.20+
     * without raising deprecation warnings on the latter. Older clients
     * that don't support the alias would need a flag — kept simple
     * because Nitro targets a modern stack.
     *
     * $update may also be an assoc map of [col => RawExpression|value] to
     * support 'INCREMENT'-style upserts; otherwise it's a list of column
     * names that map to 'col = new.col'.
     */
    public function compileUpsert(QueryBuilder $query, array $values, array $uniqueBy, array $update): string
    {
        $insert = $this->compileInsert($query, $values);

        $isAssoc = !empty($update) && array_keys($update) !== range(0, count($update) - 1);

        if ($isAssoc) {
            $clauses = [];
            foreach ($update as $col => $val) {
                $wrapped = $this->wrap($col);
                if ($val instanceof RawExpression) {
                    $clauses[] = "{$wrapped} = {$val}";
                } else {
                    $clauses[] = "{$wrapped} = ?";
                }
            }
            $updateSql = implode(', ', $clauses);
        } else {
            $updateSql = implode(', ', array_map(
                function ($col) {
                    $wrapped = $this->wrap($col);
                    return "{$wrapped} = new.{$wrapped}";
                },
                $update
            ));
        }

        return "{$insert} AS new ON DUPLICATE KEY UPDATE {$updateSql}";
    }

    /**
     * MySQL decides LIKE case sensitivity by the column's collation, which is
     * case-insensitive by default. BINARY compares the underlying bytes, which
     * is the only way to ask for a case-sensitive match without knowing the
     * collation the column was created with.
     */
    public function compileLike(string $wrappedColumn, bool $caseSensitive, bool $not): string
    {
        if (! $caseSensitive) {
            return parent::compileLike($wrappedColumn, false, $not);
        }

        return $wrappedColumn . ($not ? ' NOT LIKE BINARY ?' : ' LIKE BINARY ?');
    }

    public function compileLock(string $type): string
    {
        return match ($type) {
            'update' => 'FOR UPDATE',
            'share' => 'LOCK IN SHARE MODE',
            default => '',
        };
    }

    /**
     * RAND() takes an optional seed, which makes a "random" order repeatable —
     * the difference between a shuffled listing that paginates coherently and
     * one that shows the same row twice.
     */
    public function compileRandom(string $seed = ''): string
    {
        return 'RAND(' . ($seed === '' ? '' : (int) $seed) . ')';
    }

    /**
     * MATCH ... AGAINST, against a FULLTEXT index on those columns.
     *
     * The mode decides how the search string is read: 'boolean' honours the
     * +required/-excluded operators, the default weighs the words instead.
     *
     * @param array<int, string>   $columns
     * @param array<string, mixed> $options
     */
    public function compileFullText(array $columns, array $options): string
    {
        $wrapped = implode(', ', array_map([$this, 'wrap'], $columns));

        $mode = ($options['mode'] ?? null) === 'boolean'
            ? ' IN BOOLEAN MODE'
            : ' IN NATURAL LANGUAGE MODE';

        $expanded = ($options['expanded'] ?? false) && $mode !== ' IN BOOLEAN MODE'
            ? ' WITH QUERY EXPANSION'
            : '';

        return "MATCH ({$wrapped}) AGAINST (?{$mode}{$expanded})";
    }

    protected function compileIndexHint(QueryBuilder $query): string
    {
        $hint = $query->getIndexHint();

        if ($hint === null) {
            return '';
        }

        $index = $this->wrap($hint['index']);

        return match ($hint['type']) {
            'hint' => "USE INDEX ({$index})",
            'force' => "FORCE INDEX ({$index})",
            'ignore' => "IGNORE INDEX ({$index})",
            default => '',
        };
    }
}
