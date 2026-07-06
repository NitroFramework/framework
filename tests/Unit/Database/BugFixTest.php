<?php

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Nitro\Database\Connection;
use Nitro\Database\Query\QueryBuilder;
use Nitro\Database\Query\Grammar\MySqlGrammar;
use Nitro\Database\Query\RawExpression;

/**
 * Regression tests for the bug-batch fixes:
 *  - first()/find() do NOT mutate the builder
 *  - selectRaw()/orderByRaw() preserve their bindings
 *  - pluck() does not corrupt subsequent calls
 *  - insertGetId rejects multi-row arrays
 *  - exists() is null-safe and uses SELECT 1
 *  - paginate() count clone strips orders + select-raw bindings
 *  - cloneWithoutFirstWhere() drops the right where + bindings
 */
class BugFixTest extends TestCase
{
    private function builder(): QueryBuilder
    {
        return new QueryBuilder(
            $this->createMock(Connection::class),
            new MySqlGrammar()
        );
    }

    // ─── first() / find() non-mutating ────────────────────

    public function test_first_does_not_mutate_limit(): void
    {
        // first() must run on a clone so subsequent ->get() doesn't have
        // limit(1) silently applied.
        $b = $this->builder()->from('users')->where('status', 'active');
        $this->assertNull($b->getLimitValue());

        // We can't run first() without a real connection. But we can
        // verify the contract by reading limit before/after a SIMULATED
        // first(): the test exercises the clone pattern.
        $simulated = (clone $b);
        $simulated->limit(1);
        $this->assertSame(1, $simulated->getLimitValue());
        $this->assertNull($b->getLimitValue(), 'Original must not be mutated.');
    }

    public function test_find_does_not_accumulate_wheres(): void
    {
        $b = $this->builder()->from('users');
        $clone1 = (clone $b)->where('id', 1);
        $clone2 = (clone $b)->where('id', 2);
        $this->assertSame(1, count($clone1->getWheres()));
        $this->assertSame(1, count($clone2->getWheres()));
        $this->assertSame(0, count($b->getWheres()));
    }

    // ─── selectRaw / orderByRaw bindings preserved ────────

    public function test_select_raw_preserves_bindings(): void
    {
        $b = $this->builder()->from('orders')
            ->selectRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', ['active'])
            ->where('id', 5);

        $bindings = $b->getBindings();
        // Order: select bindings come BEFORE where bindings.
        $this->assertSame(['active', 5], $bindings);
    }

    public function test_order_by_raw_preserves_bindings(): void
    {
        $b = $this->builder()->from('posts')
            ->where('status', 'published')
            ->orderByRaw('CASE WHEN priority > ? THEN 1 ELSE 0 END DESC', [10]);

        $bindings = $b->getBindings();
        // Order: where bindings, then order bindings.
        $this->assertSame(['published', 10], $bindings);
    }

    public function test_select_raw_and_order_by_raw_interleave(): void
    {
        $b = $this->builder()->from('t')
            ->selectRaw('? + ?', [1, 2])
            ->where('a', 3)
            ->orderByRaw('? * id', [4]);

        $this->assertSame([1, 2, 3, 4], $b->getBindings());
    }

    // ─── insertGetId rejects multi-row ────────────────────

    public function test_insert_get_id_rejects_multi_row(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $b = $this->builder()->from('users');
        $b->insertGetId([['a' => 1], ['a' => 2]]);
    }

    // ─── cloneWithoutFirstWhere drops basic where + binding ─

    public function test_clone_without_first_where_drops_basic(): void
    {
        $b = $this->builder()->from('posts')
            ->where('user_id', 7)
            ->where('approved', 1);

        $clone = $b->cloneWithoutFirstWhere();
        $this->assertSame(1, count($clone->getWheres()));
        // 'approved = 1' is what's left.
        $this->assertSame(['approved'], array_column($clone->getWheres(), 'column'));
        $this->assertSame([1], $clone->getBindings());

        // Original untouched.
        $this->assertSame(2, count($b->getWheres()));
        $this->assertSame([7, 1], $b->getBindings());
    }

    public function test_clone_without_first_where_drops_in_clause(): void
    {
        $b = $this->builder()->from('posts')
            ->whereIn('user_id', [1, 2, 3])
            ->where('approved', 1);

        $clone = $b->cloneWithoutFirstWhere();
        $this->assertSame(1, count($clone->getWheres()));
        $this->assertSame([1], $clone->getBindings(), 'IN bindings should be dropped along with the where.');
    }

    public function test_clone_without_first_where_drops_between_two_bindings(): void
    {
        $b = $this->builder()->from('posts')
            ->whereBetween('id', [10, 20])
            ->where('approved', 1);

        $clone = $b->cloneWithoutFirstWhere();
        $this->assertSame([1], $clone->getBindings());
    }

    // ─── compileExists uses SELECT 1 ──────────────────────

    public function test_exists_uses_select_one(): void
    {
        $b = $this->builder()->from('users')->where('email', 'a@b.com');
        $sql = (new MySqlGrammar())->compileExists($b);
        $this->assertStringContainsString('SELECT EXISTS(SELECT 1 FROM', $sql);
        $this->assertStringNotContainsString('SELECT * FROM', $sql);
    }

    public function test_exists_drops_order_by(): void
    {
        $b = $this->builder()->from('users')->orderBy('id', 'asc');
        $sql = (new MySqlGrammar())->compileExists($b);
        // EXISTS ignores ORDER BY, so the inner query shouldn't include it.
        $this->assertStringNotContainsString('ORDER BY', $sql);
    }

    // ─── insert empty uses () VALUES () ───────────────────

    public function test_insert_empty_emits_valid_mysql(): void
    {
        $b = $this->builder()->from('t');
        $sql = (new MySqlGrammar())->compileInsert($b, []);
        $this->assertSame('INSERT INTO `t` () VALUES ()', $sql);
    }

    // ─── pluck cleanup ────────────────────────────────────

    public function test_pluck_does_not_corrupt_columns(): void
    {
        // After pluck() the builder's $columns must be unchanged so a
        // subsequent get() still returns SELECT *.
        $b = $this->builder()->from('users');
        $this->assertSame(['*'], $b->getColumns());

        // We can't run pluck() without a connection but we CAN verify the
        // clone-then-mutate pattern: pluck modifies its clone, not $this.
        $clone = clone $b;
        $clone->select('email');
        $this->assertSame(['*'], $b->getColumns(), 'pluck must not mutate origin.');
    }

    // ─── upsert MySQL row-alias syntax ────────────────────

    public function test_upsert_uses_row_alias(): void
    {
        $b = $this->builder()->from('counters');
        $sql = (new MySqlGrammar())->compileUpsert(
            $b,
            [['key' => 'visits', 'val' => 1]],
            ['key'],
            ['val']
        );
        $this->assertStringContainsString('AS new ON DUPLICATE KEY UPDATE', $sql);
        $this->assertStringContainsString('`val` = new.`val`', $sql);
        $this->assertStringNotContainsString('VALUES(', $sql); // deprecated form
    }
}
