<?php

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Nitro\Database\Connection;
use Nitro\Database\Query\Grammar\Grammar;
use Nitro\Database\Query\QueryBuilder;
use Nitro\Database\Query\RawExpression;

class QueryBuilderTest extends TestCase
{
    protected function builder(): QueryBuilder
    {
        $connection = $this->createMock(Connection::class);
        return new QueryBuilder($connection, new Grammar());
    }

    // =========================================================================
    // Fluent Interface — Methods Return $this
    // =========================================================================

    public function test_from_returns_builder(): void
    {
        $q = $this->builder();
        $this->assertSame($q, $q->from('users'));
    }

    public function test_table_returns_builder(): void
    {
        $q = $this->builder();
        $this->assertSame($q, $q->table('users'));
    }

    public function test_select_returns_builder(): void
    {
        $q = $this->builder();
        $this->assertSame($q, $q->select('id', 'name'));
    }

    public function test_add_select_returns_builder(): void
    {
        $q = $this->builder();
        $this->assertSame($q, $q->addSelect('email'));
    }

    public function test_distinct_returns_builder(): void
    {
        $q = $this->builder();
        $this->assertSame($q, $q->distinct());
    }

    public function test_where_returns_builder(): void
    {
        $q = $this->builder();
        $this->assertSame($q, $q->where('id', 1));
    }

    public function test_or_where_returns_builder(): void
    {
        $q = $this->builder()->where('a', 1);
        $this->assertSame($q, $q->orWhere('b', 2));
    }

    public function test_where_in_returns_builder(): void
    {
        $q = $this->builder();
        $this->assertSame($q, $q->whereIn('id', [1, 2]));
    }

    public function test_order_by_returns_builder(): void
    {
        $q = $this->builder();
        $this->assertSame($q, $q->orderBy('name'));
    }

    public function test_group_by_returns_builder(): void
    {
        $q = $this->builder();
        $this->assertSame($q, $q->groupBy('status'));
    }

    public function test_having_returns_builder(): void
    {
        $q = $this->builder();
        $this->assertSame($q, $q->having('cnt', '>', 5));
    }

    public function test_limit_returns_builder(): void
    {
        $q = $this->builder();
        $this->assertSame($q, $q->limit(10));
    }

    public function test_offset_returns_builder(): void
    {
        $q = $this->builder();
        $this->assertSame($q, $q->offset(20));
    }

    public function test_join_returns_builder(): void
    {
        $q = $this->builder();
        $this->assertSame($q, $q->join('posts', 'users.id', '=', 'posts.user_id'));
    }

    // =========================================================================
    // Getters
    // =========================================================================

    public function test_get_from(): void
    {
        $this->assertSame('users', $this->builder()->from('users')->getFrom());
    }

    public function test_get_columns_default(): void
    {
        $this->assertSame(['*'], $this->builder()->getColumns());
    }

    public function test_get_columns_after_select(): void
    {
        $q = $this->builder()->select('id', 'name');
        $this->assertSame(['id', 'name'], $q->getColumns());
    }

    public function test_is_distinct_default_false(): void
    {
        $this->assertFalse($this->builder()->isDistinct());
    }

    public function test_is_distinct_after_distinct(): void
    {
        $this->assertTrue($this->builder()->distinct()->isDistinct());
    }

    public function test_get_wheres_empty(): void
    {
        $this->assertSame([], $this->builder()->getWheres());
    }

    public function test_get_wheres_after_where(): void
    {
        $q = $this->builder()->where('id', 1);
        $this->assertCount(1, $q->getWheres());
    }

    public function test_get_joins_empty(): void
    {
        $this->assertSame([], $this->builder()->getJoins());
    }

    public function test_get_joins_after_join(): void
    {
        $q = $this->builder()->join('posts', 'users.id', '=', 'posts.user_id');
        $this->assertCount(1, $q->getJoins());
    }

    public function test_get_groups_empty(): void
    {
        $this->assertSame([], $this->builder()->getGroups());
    }

    public function test_get_groups_after_group_by(): void
    {
        $q = $this->builder()->groupBy('status', 'region');
        $this->assertSame(['status', 'region'], $q->getGroups());
    }

    public function test_get_havings_empty(): void
    {
        $this->assertSame([], $this->builder()->getHavings());
    }

    public function test_get_orders_empty(): void
    {
        $this->assertSame([], $this->builder()->getOrders());
    }

    public function test_get_limit_value_default_null(): void
    {
        $this->assertNull($this->builder()->getLimitValue());
    }

    public function test_get_limit_value(): void
    {
        $this->assertSame(10, $this->builder()->limit(10)->getLimitValue());
    }

    public function test_get_offset_value_default_null(): void
    {
        $this->assertNull($this->builder()->getOffsetValue());
    }

    public function test_get_offset_value(): void
    {
        $this->assertSame(20, $this->builder()->offset(20)->getOffsetValue());
    }

    public function test_get_connection(): void
    {
        $q = $this->builder();
        $this->assertInstanceOf(Connection::class, $q->getConnection());
    }

    public function test_get_grammar(): void
    {
        $q = $this->builder();
        $this->assertInstanceOf(Grammar::class, $q->getGrammar());
    }

    // =========================================================================
    // Bindings
    // =========================================================================

    public function test_bindings_empty_by_default(): void
    {
        $this->assertSame([], $this->builder()->getBindings());
    }

    public function test_where_adds_binding(): void
    {
        $q = $this->builder()->where('id', 1);
        $this->assertSame([1], $q->getBindings());
    }

    public function test_multiple_wheres_accumulate_bindings(): void
    {
        $q = $this->builder()
            ->where('status', 'active')
            ->where('role', 'admin');
        $this->assertSame(['active', 'admin'], $q->getBindings());
    }

    public function test_where_in_adds_multiple_bindings(): void
    {
        $q = $this->builder()->whereIn('id', [1, 2, 3]);
        $this->assertSame([1, 2, 3], $q->getBindings());
    }

    public function test_where_between_adds_two_bindings(): void
    {
        $q = $this->builder()->whereBetween('age', [18, 65]);
        $this->assertSame([18, 65], $q->getBindings());
    }

    public function test_where_null_adds_no_bindings(): void
    {
        $q = $this->builder()->whereNull('deleted_at');
        $this->assertSame([], $q->getBindings());
    }

    public function test_where_not_null_adds_no_bindings(): void
    {
        $q = $this->builder()->whereNotNull('email');
        $this->assertSame([], $q->getBindings());
    }

    public function test_having_adds_binding(): void
    {
        $q = $this->builder()->having('cnt', '>', 5);
        $this->assertSame([5], $q->getBindings());
    }

    public function test_mixed_bindings_in_order(): void
    {
        $q = $this->builder()
            ->from('orders')
            ->where('status', 'completed')
            ->whereIn('region', ['US', 'EU'])
            ->having('total', '>', 100);
        $this->assertSame(['completed', 'US', 'EU', 100], $q->getBindings());
    }

    public function test_where_raw_bindings(): void
    {
        $q = $this->builder()->whereRaw('YEAR(created_at) = ?', [2024]);
        $this->assertSame([2024], $q->getBindings());
    }

    public function test_select_raw_bindings(): void
    {
        $q = $this->builder()->selectRaw('COUNT(*) as cnt');
        // selectRaw with no bindings should not add anything
        $this->assertSame([], $q->getBindings());
    }

    // =========================================================================
    // Where Structures
    // =========================================================================

    public function test_where_structure_basic(): void
    {
        $q = $this->builder()->where('id', '=', 1);
        $where = $q->getWheres()[0];
        $this->assertSame('basic', $where['type']);
        $this->assertSame('id', $where['column']);
        $this->assertSame('=', $where['operator']);
    }

    public function test_where_two_args_infers_equals(): void
    {
        $q = $this->builder()->where('name', 'John');
        $where = $q->getWheres()[0];
        $this->assertSame('=', $where['operator']);
    }

    public function test_where_in_structure(): void
    {
        $q = $this->builder()->whereIn('id', [1, 2, 3]);
        $where = $q->getWheres()[0];
        $this->assertSame('in', $where['type']);
        $this->assertSame([1, 2, 3], $where['values']);
    }

    public function test_where_not_in_structure(): void
    {
        $q = $this->builder()->whereNotIn('id', [4, 5]);
        $where = $q->getWheres()[0];
        $this->assertSame('not_in', $where['type']);
    }

    public function test_where_null_structure(): void
    {
        $q = $this->builder()->whereNull('deleted_at');
        $where = $q->getWheres()[0];
        $this->assertSame('null', $where['type']);
        $this->assertSame('deleted_at', $where['column']);
    }

    public function test_where_not_null_structure(): void
    {
        $q = $this->builder()->whereNotNull('email');
        $where = $q->getWheres()[0];
        $this->assertSame('not_null', $where['type']);
    }

    public function test_where_between_structure(): void
    {
        $q = $this->builder()->whereBetween('age', [18, 65]);
        $where = $q->getWheres()[0];
        $this->assertSame('between', $where['type']);
    }

    public function test_where_column_structure(): void
    {
        $q = $this->builder()->whereColumn('updated_at', '>', 'created_at');
        $where = $q->getWheres()[0];
        $this->assertSame('column', $where['type']);
        $this->assertSame('updated_at', $where['first']);
        $this->assertSame('created_at', $where['second']);
    }

    public function test_or_where_boolean(): void
    {
        $q = $this->builder()
            ->where('a', 1)
            ->orWhere('b', 2);
        $this->assertSame('OR', $q->getWheres()[1]['boolean']);
    }

    public function test_and_where_boolean(): void
    {
        $q = $this->builder()
            ->where('a', 1)
            ->where('b', 2);
        $this->assertSame('AND', $q->getWheres()[1]['boolean']);
    }

    // =========================================================================
    // Join Structures
    // =========================================================================

    public function test_inner_join_structure(): void
    {
        $q = $this->builder()->join('posts', 'users.id', '=', 'posts.user_id');
        $join = $q->getJoins()[0];
        $this->assertSame('inner', $join['type']);
        $this->assertSame('posts', $join['table']);
    }

    public function test_left_join_structure(): void
    {
        $q = $this->builder()->leftJoin('profiles', 'users.id', '=', 'profiles.user_id');
        $this->assertSame('left', $q->getJoins()[0]['type']);
    }

    public function test_right_join_structure(): void
    {
        $q = $this->builder()->rightJoin('orders', 'users.id', '=', 'orders.user_id');
        $this->assertSame('right', $q->getJoins()[0]['type']);
    }

    public function test_cross_join_structure(): void
    {
        $q = $this->builder()->crossJoin('sizes');
        $this->assertSame('cross', $q->getJoins()[0]['type']);
    }

    // =========================================================================
    // Order Structures
    // =========================================================================

    public function test_order_by_structure(): void
    {
        $q = $this->builder()->orderBy('name', 'asc');
        $order = $q->getOrders()[0];
        $this->assertSame('name', $order['column']);
        $this->assertSame('ASC', $order['direction']);
    }

    public function test_order_by_desc_structure(): void
    {
        $q = $this->builder()->orderByDesc('created_at');
        $this->assertSame('DESC', $q->getOrders()[0]['direction']);
    }

    public function test_latest_orders_by_created_at_desc(): void
    {
        $q = $this->builder()->latest();
        $order = $q->getOrders()[0];
        $this->assertSame('created_at', $order['column']);
        $this->assertSame('DESC', $order['direction']);
    }

    public function test_latest_custom_column(): void
    {
        $q = $this->builder()->latest('updated_at');
        $this->assertSame('updated_at', $q->getOrders()[0]['column']);
    }

    public function test_oldest_orders_by_created_at_asc(): void
    {
        $q = $this->builder()->oldest();
        $order = $q->getOrders()[0];
        $this->assertSame('created_at', $order['column']);
        $this->assertSame('ASC', $order['direction']);
    }

    public function test_order_by_raw_structure(): void
    {
        $q = $this->builder()->orderByRaw('RAND()');
        $this->assertInstanceOf(RawExpression::class, $q->getOrders()[0]);
    }

    // =========================================================================
    // Aliases
    // =========================================================================

    public function test_table_is_alias_for_from(): void
    {
        $a = $this->builder()->from('users')->getFrom();
        $b = $this->builder()->table('users')->getFrom();
        $this->assertSame($a, $b);
    }

    public function test_take_is_alias_for_limit(): void
    {
        $this->assertSame(5, $this->builder()->take(5)->getLimitValue());
    }

    public function test_skip_is_alias_for_offset(): void
    {
        $this->assertSame(10, $this->builder()->skip(10)->getOffsetValue());
    }

    // =========================================================================
    // Select Overwrite vs Append
    // =========================================================================

    public function test_select_replaces_columns(): void
    {
        $q = $this->builder()->select('id')->select('name');
        $this->assertSame(['name'], $q->getColumns());
    }

    public function test_add_select_appends(): void
    {
        $q = $this->builder()->select('id')->addSelect('name');
        $this->assertSame(['id', 'name'], $q->getColumns());
    }

    public function test_add_select_array(): void
    {
        $q = $this->builder()->select('id')->addSelect(['name', 'email']);
        $this->assertSame(['id', 'name', 'email'], $q->getColumns());
    }

    // =========================================================================
    // Cloning
    // =========================================================================

    public function test_to_sql_output(): void
    {
        $sql = $this->builder()->from('users')->where('active', 1)->toSql();
        $this->assertSame('SELECT * FROM `users` WHERE `active` = ?', $sql);
    }

    // =========================================================================
    // When (Conditional)
    // =========================================================================

    public function test_when_truthy_applies_callback(): void
    {
        $q = $this->builder()->from('users')
            ->when('admin', fn($q) => $q->where('role', 'admin'));
        $this->assertCount(1, $q->getWheres());
    }

    public function test_when_falsy_skips_callback(): void
    {
        $q = $this->builder()->from('users')
            ->when(false, fn($q) => $q->where('role', 'admin'));
        $this->assertCount(0, $q->getWheres());
    }

    public function test_when_falsy_runs_default(): void
    {
        $q = $this->builder()->from('users')
            ->when(
                false,
                fn($q) => $q->where('role', 'admin'),
                fn($q) => $q->where('role', 'user')
            );
        $this->assertCount(1, $q->getWheres());
    }

    public function test_when_null_is_falsy(): void
    {
        $q = $this->builder()->from('users')
            ->when(null, fn($q) => $q->where('role', 'admin'));
        $this->assertCount(0, $q->getWheres());
    }

    public function test_when_empty_string_is_falsy(): void
    {
        $q = $this->builder()->from('users')
            ->when('', fn($q) => $q->where('role', 'admin'));
        $this->assertCount(0, $q->getWheres());
    }

    public function test_when_passes_condition_value(): void
    {
        $q = $this->builder()->from('users')
            ->when('admin', function ($q, $role) {
                $q->where('role', $role);
            });
        $this->assertSame(['admin'], $q->getBindings());
    }

    // =========================================================================
    // Chaining Complex Queries
    // =========================================================================

    public function test_full_chain(): void
    {
        $q = $this->builder()
            ->from('orders')
            ->select('user_id', new RawExpression('SUM(total) as revenue'))
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->where('orders.status', 'completed')
            ->whereNotNull('orders.paid_at')
            ->whereBetween('orders.total', [10, 1000])
            ->groupBy('user_id')
            ->having('revenue', '>', 500)
            ->orderByDesc('revenue')
            ->limit(20)
            ->offset(0);

        $this->assertSame('orders', $q->getFrom());
        $this->assertCount(2, $q->getColumns());
        $this->assertCount(1, $q->getJoins());
        $this->assertCount(3, $q->getWheres());
        $this->assertSame(['user_id'], $q->getGroups());
        $this->assertCount(1, $q->getHavings());
        $this->assertCount(1, $q->getOrders());
        $this->assertSame(20, $q->getLimitValue());
        $this->assertSame(0, $q->getOffsetValue());
        $this->assertTrue($q->isDistinct() === false);
    }

    public function test_full_chain_bindings(): void
    {
        $q = $this->builder()
            ->from('orders')
            ->where('status', 'completed')
            ->whereIn('type', ['online', 'store'])
            ->whereBetween('total', [100, 999])
            ->whereNotIn('region', ['test'])
            ->having('cnt', '>', 3);

        $this->assertSame(['completed', 'online', 'store', 100, 999, 'test', 3], $q->getBindings());
    }

    public function test_full_chain_sql(): void
    {
        $sql = $this->builder()
            ->from('users')
            ->select('id', 'name')
            ->where('active', 1)
            ->whereNotNull('email')
            ->orderBy('name')
            ->limit(10)
            ->toSql();

        $this->assertSame(
            'SELECT `id`, `name` FROM `users` WHERE `active` = ? AND `email` IS NOT NULL ORDER BY `name` ASC LIMIT 10',
            $sql
        );
    }
}