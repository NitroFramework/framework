<?php

namespace Tests\Unit\Database;

use Nitro\Database\Connection;
use Nitro\Database\Query\Grammar\SqliteGrammar;
use Nitro\Database\Query\QueryBuilder;
use Nitro\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Asking about a date without writing the comparison out.
 *
 * Every one of these was expressible before — whereDate('created_at', '=',
 * now()->toDateString()) says the same thing — but the application had to know
 * which of date and datetime the column was, and produce the value in the
 * format the grammar wanted. Getting that wrong compiles and returns nothing.
 */
class QueryDateClausesTest extends TestCase
{
    private function builder(): QueryBuilder
    {
        $connection = new class extends Connection {
            public function __construct()
            {
            }

            public function select(string $sql, array $bindings = []): array
            {
                return [];
            }
        };

        return (new QueryBuilder($connection, new SqliteGrammar()))->table('orders');
    }

    private function sqlOf(QueryBuilder $query): string
    {
        return (new SqliteGrammar())->compileSelect($query);
    }

    // ─── Against this moment ──────────────────────────────

    public function test_where_past_compares_to_now(): void
    {
        $query = $this->builder()->wherePast('expires_at');

        $this->assertSame(
            'SELECT * FROM "orders" WHERE "expires_at" < ?',
            $this->sqlOf($query),
        );

        $this->assertSame([Carbon::now()->format('Y-m-d H:i:s')], $query->getBindings());
    }

    public function test_where_future_compares_the_other_way(): void
    {
        $this->assertSame(
            'SELECT * FROM "orders" WHERE "starts_at" > ?',
            $this->sqlOf($this->builder()->whereFuture('starts_at')),
        );
    }

    public function test_the_now_or_variants_are_inclusive(): void
    {
        $this->assertSame(
            'SELECT * FROM "orders" WHERE "seen_at" <= ?',
            $this->sqlOf($this->builder()->whereNowOrPast('seen_at')),
        );

        $this->assertSame(
            'SELECT * FROM "orders" WHERE "ends_at" >= ?',
            $this->sqlOf($this->builder()->whereNowOrFuture('ends_at')),
        );
    }

    // ─── Against the day ──────────────────────────────────

    public function test_where_today_compares_the_date_part_only(): void
    {
        $query = $this->builder()->whereToday('created_at');

        $this->assertSame(
            'SELECT * FROM "orders" WHERE strftime(\'%Y-%m-%d\', "created_at") = cast(? as text)',
            $this->sqlOf($query),
        );

        $this->assertSame([Carbon::today()->format('Y-m-d')], $query->getBindings());
    }

    public function test_before_and_after_today(): void
    {
        $this->assertSame(
            'SELECT * FROM "orders" WHERE strftime(\'%Y-%m-%d\', "created_at") < cast(? as text)',
            $this->sqlOf($this->builder()->whereBeforeToday('created_at')),
        );

        $this->assertSame(
            'SELECT * FROM "orders" WHERE strftime(\'%Y-%m-%d\', "created_at") > cast(? as text)',
            $this->sqlOf($this->builder()->whereAfterToday('created_at')),
        );
    }

    public function test_today_or_either_side_is_inclusive(): void
    {
        $this->assertSame(
            'SELECT * FROM "orders" WHERE strftime(\'%Y-%m-%d\', "created_at") <= cast(? as text)',
            $this->sqlOf($this->builder()->whereTodayOrBefore('created_at')),
        );

        $this->assertSame(
            'SELECT * FROM "orders" WHERE strftime(\'%Y-%m-%d\', "created_at") >= cast(? as text)',
            $this->sqlOf($this->builder()->whereTodayOrAfter('created_at')),
        );
    }

    // ─── Shapes ───────────────────────────────────────────

    public function test_several_columns_are_anded(): void
    {
        $this->assertSame(
            'SELECT * FROM "orders" WHERE "starts_at" > ? AND "ends_at" > ?',
            $this->sqlOf($this->builder()->whereFuture(['starts_at', 'ends_at'])),
        );
    }

    public function test_the_or_variants_join_with_or(): void
    {
        $this->assertSame(
            'SELECT * FROM "orders" WHERE "status" = ? OR "expires_at" < ?',
            $this->sqlOf($this->builder()->where('status', 'paid')->orWherePast('expires_at')),
        );

        $this->assertSame(
            'SELECT * FROM "orders" WHERE "status" = ? '
                . 'OR strftime(\'%Y-%m-%d\', "created_at") = cast(? as text)',
            $this->sqlOf($this->builder()->where('status', 'paid')->orWhereToday('created_at')),
        );
    }
}
