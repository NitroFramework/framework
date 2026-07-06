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

    public function compileLock(string $type): string
    {
        return match ($type) {
            'update' => 'FOR UPDATE',
            'share' => 'LOCK IN SHARE MODE',
            default => '',
        };
    }
}
