<?php

namespace Tests\Unit\Database;

use InvalidArgumentException;
use Nitro\Database\Connection;
use Nitro\Database\Query\Grammar\Grammar;
use Nitro\Database\Query\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Confirms the Grammar layer rejects identifier inputs that could carry
 * SQL injection payloads. These tests guard against regressions in the
 * wrapping helpers (wrap, wrapTable, wrapSegment, validateOperator,
 * validateDirection, validateAggregateFunction).
 */
class GrammarSecurityTest extends TestCase
{
    protected Grammar $grammar;

    protected function setUp(): void
    {
        $this->grammar = new Grammar();
    }

    protected function builder(): QueryBuilder
    {
        return new QueryBuilder($this->createMock(Connection::class), $this->grammar);
    }

    public function test_table_injection_via_from_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()->from('users; DROP TABLE users; --')->toSql();
    }

    public function test_column_injection_via_select_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()->from('users')->select('id) UNION SELECT password FROM admins --')->toSql();
    }

    public function test_order_by_column_injection_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()->from('users')->orderBy('id) OR 1=1 --')->toSql();
    }

    public function test_order_by_direction_injection_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()->from('users')->orderBy('id', 'asc; DROP TABLE users')->toSql();
    }

    public function test_group_by_injection_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()->from('users')->groupBy('id, (SELECT password FROM admins)')->toSql();
    }

    public function test_where_column_injection_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()->from('users')->where('id) OR 1=1; --', '=', 1)->toSql();
    }

    public function test_where_operator_injection_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()->from('users')->where('id', 'OR 1=1; --', 1)->toSql();
    }

    public function test_join_table_injection_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()
            ->from('users')
            ->join('posts; DROP TABLE users; --', 'users.id', '=', 'posts.user_id')
            ->toSql();
    }

    public function test_join_operator_injection_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()
            ->from('users')
            ->join('posts', 'users.id', 'OR 1=1 --', 'posts.user_id')
            ->toSql();
    }

    public function test_aggregate_function_name_is_validated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->grammar->compileAggregate($this->builder()->from('users'), 'SLEEP(1));--', '*');
    }

    public function test_aggregate_column_injection_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->grammar->compileAggregate($this->builder()->from('users'), 'COUNT', '*) UNION SELECT NULL --');
    }

    public function test_insert_column_injection_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->grammar->compileInsert(
            $this->builder()->from('users'),
            ['name) VALUES ((SELECT password FROM admins)) --' => 'x']
        );
    }

    public function test_update_column_injection_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->grammar->compileUpdate(
            $this->builder()->from('users'),
            ['name = (SELECT password FROM admins) --' => 'x']
        );
    }

    public function test_empty_table_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()->from('')->toSql();
    }

    // Sanity: legitimate qualified, aliased, and starred identifiers still work.

    public function test_qualified_identifier_is_accepted(): void
    {
        $sql = $this->builder()->from('users')->select('users.id')->where('users.id', 1)->toSql();
        $this->assertStringContainsString('`users`.`id`', $sql);
    }

    public function test_aliased_table_is_accepted(): void
    {
        // table aliasing via "table AS alias" is supported by wrapTable.
        $sql = $this->builder()->from('users as u')->toSql();
        $this->assertStringContainsString('`users` AS `u`', $sql);
    }

    public function test_wildcard_column_is_accepted(): void
    {
        $sql = $this->builder()->from('users')->select('users.*')->toSql();
        $this->assertStringContainsString('`users`.*', $sql);
    }

    public function test_aggregate_lowercase_function_is_normalized(): void
    {
        $sql = $this->grammar->compileAggregate($this->builder()->from('orders'), 'sum', 'total');
        $this->assertStringContainsString('SELECT SUM(`total`) AS aggregate', $sql);
    }
}
