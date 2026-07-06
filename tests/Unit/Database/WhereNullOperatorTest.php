<?php

namespace Tests\Unit\Database;

use InvalidArgumentException;
use Nitro\Database\Connection;
use Nitro\Database\Query\Grammar\Grammar;
use Nitro\Database\Query\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the where()/orWhere() operator-value split.
 *
 * The old code shifted operator→value whenever $value === null, which
 * corrupted explicit null comparisons (where('col', '!=', null) compiled to
 * `col = '!='`). The fix mirrors Laravel: the shift is gated purely on the
 * argument count, and orWhere prepares its own pair before delegating.
 */
class WhereNullOperatorTest extends TestCase
{
    private function builder(): QueryBuilder
    {
        return new QueryBuilder($this->createMock(Connection::class), new Grammar());
    }

    public function test_explicit_not_equal_null_becomes_is_not_null(): void
    {
        $q = $this->builder()->from('users')->where('deleted_at', '!=', null);

        $this->assertStringContainsString('`deleted_at` IS NOT NULL', $q->toSql());
        $this->assertSame([], $q->getBindings());
    }

    public function test_explicit_equal_null_becomes_is_null(): void
    {
        $q = $this->builder()->from('users')->where('deleted_at', '=', null);

        $this->assertStringContainsString('`deleted_at` IS NULL', $q->toSql());
        $this->assertSame([], $q->getBindings());
    }

    public function test_two_arg_shorthand_still_defaults_to_equals(): void
    {
        $q = $this->builder()->from('users')->where('status', 'active');

        $this->assertStringContainsString('`status` = ?', $q->toSql());
        $this->assertSame(['active'], $q->getBindings());
    }

    public function test_two_arg_null_shorthand_becomes_is_null(): void
    {
        $q = $this->builder()->from('users')->where('deleted_at', null);

        $this->assertStringContainsString('`deleted_at` IS NULL', $q->toSql());
        $this->assertSame([], $q->getBindings());
    }

    public function test_real_operator_and_value_are_not_shifted(): void
    {
        $q = $this->builder()->from('users')->where('votes', '!=', 5);

        $this->assertStringContainsString('`votes` != ?', $q->toSql());
        $this->assertSame([5], $q->getBindings());
    }

    public function test_or_where_two_arg_shorthand_is_not_corrupted(): void
    {
        // orWhere passes 4 args to where(); it must prepare the pair itself so
        // the value ('bar') is not mistaken for the operator.
        $q = $this->builder()->from('users')->where('a', 1)->orWhere('name', 'bar');

        $sql = $q->toSql();
        $this->assertStringContainsString('OR `name` = ?', $sql);
        $this->assertSame([1, 'bar'], $q->getBindings());
    }

    public function test_or_where_explicit_null_becomes_is_not_null(): void
    {
        $q = $this->builder()->from('users')->where('a', 1)->orWhere('deleted_at', '!=', null);

        $this->assertStringContainsString('OR `deleted_at` IS NOT NULL', $q->toSql());
        $this->assertSame([1], $q->getBindings());
    }

    public function test_illegal_operator_with_null_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()->from('users')->where('votes', '>', null);
    }
}
