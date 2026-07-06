<?php

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Nitro\Database\Connection;
use Nitro\Database\Query\Grammar\Grammar;
use Nitro\Database\Query\QueryBuilder;
use Nitro\Database\Query\RawExpression;

/**
 * Grammar compilation tests.
 *
 * Note: as of the SQL-injection hardening pass, all identifiers (tables,
 * columns, qualified names, aliases) are wrapped in MySQL-style backticks.
 * RawExpression values pass through untouched.
 */
class GrammarTest extends TestCase
{
    protected Grammar $grammar;

    protected function setUp(): void
    {
        $this->grammar = new Grammar();
    }

    protected function builder(): QueryBuilder
    {
        $connection = $this->createMock(Connection::class);
        return new QueryBuilder($connection, $this->grammar);
    }

    // =========================================================================
    // SELECT — Basic
    // =========================================================================

    public function test_select_all_columns(): void
    {
        $sql = $this->builder()->from('users')->toSql();
        $this->assertSame('SELECT * FROM `users`', $sql);
    }

    public function test_select_specific_columns(): void
    {
        $sql = $this->builder()->from('users')->select('id', 'name', 'email')->toSql();
        $this->assertSame('SELECT `id`, `name`, `email` FROM `users`', $sql);
    }

    public function test_select_with_array(): void
    {
        $sql = $this->builder()->from('users')->select(['id', 'name'])->toSql();
        $this->assertSame('SELECT `id`, `name` FROM `users`', $sql);
    }

    public function test_select_distinct(): void
    {
        $sql = $this->builder()->from('users')->select('status')->distinct()->toSql();
        $this->assertSame('SELECT DISTINCT `status` FROM `users`', $sql);
    }

    public function test_select_raw_expression(): void
    {
        $sql = $this->builder()->from('users')
            ->select(new RawExpression('COUNT(*) as total'))
            ->toSql();
        $this->assertSame('SELECT COUNT(*) as total FROM `users`', $sql);
    }

    public function test_add_select(): void
    {
        $sql = $this->builder()->from('users')
            ->select('id')
            ->addSelect('name')
            ->toSql();
        $this->assertSame('SELECT `id`, `name` FROM `users`', $sql);
    }

    public function test_select_raw_appends_to_existing_columns(): void
    {
        // selectRaw() APPENDS rather than replacing — matches Laravel.
        // To replace, the caller does select() first or passes the raw via
        // select(DB::raw('…')).
        $sql = $this->builder()->from('orders')
            ->selectRaw('SUM(total) as revenue')
            ->toSql();
        $this->assertSame('SELECT *, SUM(total) as revenue FROM `orders`', $sql);
    }

    public function test_select_raw_after_explicit_select(): void
    {
        $sql = $this->builder()->from('orders')
            ->select('id', 'status')
            ->selectRaw('SUM(total) as revenue')
            ->toSql();
        $this->assertSame('SELECT `id`, `status`, SUM(total) as revenue FROM `orders`', $sql);
    }

    public function test_qualified_column_is_wrapped_per_segment(): void
    {
        $sql = $this->builder()->from('users')->select('users.id', 'users.name')->toSql();
        $this->assertSame('SELECT `users`.`id`, `users`.`name` FROM `users`', $sql);
    }

    public function test_qualified_star_kept_unwrapped(): void
    {
        $sql = $this->builder()->from('users')->select('users.*')->toSql();
        $this->assertSame('SELECT `users`.* FROM `users`', $sql);
    }

    public function test_aliased_column(): void
    {
        $sql = $this->builder()->from('users')->select('name as full_name')->toSql();
        $this->assertSame('SELECT `name` AS `full_name` FROM `users`', $sql);
    }

    // =========================================================================
    // WHERE — Basic
    // =========================================================================

    public function test_where_equals(): void
    {
        $q = $this->builder()->from('users')->where('id', '=', 1);
        $this->assertSame('SELECT * FROM `users` WHERE `id` = ?', $q->toSql());
        $this->assertSame([1], $q->getBindings());
    }

    public function test_where_two_args_defaults_to_equals(): void
    {
        $q = $this->builder()->from('users')->where('name', 'John');
        $this->assertSame('SELECT * FROM `users` WHERE `name` = ?', $q->toSql());
        $this->assertSame(['John'], $q->getBindings());
    }

    public function test_where_operators(): void
    {
        $q = $this->builder()->from('users')->where('age', '>', 18);
        $this->assertSame('SELECT * FROM `users` WHERE `age` > ?', $q->toSql());
    }

    public function test_where_multiple_and(): void
    {
        $q = $this->builder()->from('users')
            ->where('status', 'active')
            ->where('role', 'admin');
        $this->assertSame('SELECT * FROM `users` WHERE `status` = ? AND `role` = ?', $q->toSql());
        $this->assertSame(['active', 'admin'], $q->getBindings());
    }

    public function test_or_where(): void
    {
        $q = $this->builder()->from('users')
            ->where('name', 'Alice')
            ->orWhere('name', 'Bob');
        $this->assertSame('SELECT * FROM `users` WHERE `name` = ? OR `name` = ?', $q->toSql());
        $this->assertSame(['Alice', 'Bob'], $q->getBindings());
    }

    public function test_where_in(): void
    {
        $q = $this->builder()->from('users')->whereIn('id', [1, 2, 3]);
        $this->assertSame('SELECT * FROM `users` WHERE `id` IN (?, ?, ?)', $q->toSql());
        $this->assertSame([1, 2, 3], $q->getBindings());
    }

    public function test_where_not_in(): void
    {
        $q = $this->builder()->from('users')->whereNotIn('status', ['banned', 'deleted']);
        $this->assertSame('SELECT * FROM `users` WHERE `status` NOT IN (?, ?)', $q->toSql());
        $this->assertSame(['banned', 'deleted'], $q->getBindings());
    }

    public function test_where_null(): void
    {
        $q = $this->builder()->from('users')->whereNull('deleted_at');
        $this->assertSame('SELECT * FROM `users` WHERE `deleted_at` IS NULL', $q->toSql());
    }

    public function test_where_not_null(): void
    {
        $q = $this->builder()->from('users')->whereNotNull('email');
        $this->assertSame('SELECT * FROM `users` WHERE `email` IS NOT NULL', $q->toSql());
    }

    public function test_where_between(): void
    {
        $q = $this->builder()->from('users')->whereBetween('age', [18, 65]);
        $this->assertSame('SELECT * FROM `users` WHERE `age` BETWEEN ? AND ?', $q->toSql());
        $this->assertSame([18, 65], $q->getBindings());
    }

    public function test_where_column(): void
    {
        $q = $this->builder()->from('users')->whereColumn('updated_at', '>', 'created_at');
        $this->assertSame('SELECT * FROM `users` WHERE `updated_at` > `created_at`', $q->toSql());
    }

    public function test_where_raw(): void
    {
        $q = $this->builder()->from('users')->whereRaw('YEAR(created_at) = ?', [2024]);
        $this->assertSame('SELECT * FROM `users` WHERE YEAR(created_at) = ?', $q->toSql());
        $this->assertSame([2024], $q->getBindings());
    }

    // =========================================================================
    // WHERE — Or Variants
    // =========================================================================

    public function test_or_where_in(): void
    {
        $q = $this->builder()->from('users')
            ->where('status', 'active')
            ->orWhereIn('role', ['admin', 'editor']);
        $this->assertStringContainsString('OR `role` IN (?, ?)', $q->toSql());
    }

    public function test_or_where_null(): void
    {
        $q = $this->builder()->from('users')
            ->where('status', 'active')
            ->orWhereNull('deleted_at');
        $this->assertStringContainsString('OR `deleted_at` IS NULL', $q->toSql());
    }

    public function test_or_where_not_null(): void
    {
        $q = $this->builder()->from('users')
            ->where('status', 'active')
            ->orWhereNotNull('verified_at');
        $this->assertStringContainsString('OR `verified_at` IS NOT NULL', $q->toSql());
    }

    public function test_or_where_not_in(): void
    {
        $q = $this->builder()->from('users')
            ->where('status', 'active')
            ->orWhereNotIn('role', ['banned']);
        $this->assertStringContainsString('OR `role` NOT IN (?)', $q->toSql());
    }

    public function test_or_where_between(): void
    {
        $q = $this->builder()->from('users')
            ->where('status', 'active')
            ->orWhereBetween('age', [18, 25]);
        $this->assertStringContainsString('OR `age` BETWEEN ? AND ?', $q->toSql());
    }

    // =========================================================================
    // WHERE — Nested / Grouped
    // =========================================================================

    public function test_where_nested(): void
    {
        $q = $this->builder()->from('users')
            ->where('status', 'active')
            ->where(function ($query) {
                $query->where('role', 'admin')
                      ->orWhere('role', 'editor');
            });
        $sql = $q->toSql();
        $this->assertStringContainsString('(`role` = ? OR `role` = ?)', $sql);
    }

    // =========================================================================
    // WHERE — Combined Complex
    // =========================================================================

    public function test_where_complex_combination(): void
    {
        $q = $this->builder()->from('orders')
            ->where('status', 'completed')
            ->whereNotNull('shipped_at')
            ->whereBetween('total', [100, 500])
            ->whereIn('region', ['US', 'EU']);

        $sql = $q->toSql();
        $this->assertStringContainsString('`status` = ?', $sql);
        $this->assertStringContainsString('`shipped_at` IS NOT NULL', $sql);
        $this->assertStringContainsString('`total` BETWEEN ? AND ?', $sql);
        $this->assertStringContainsString('`region` IN (?, ?)', $sql);
        $this->assertSame(['completed', 100, 500, 'US', 'EU'], $q->getBindings());
    }

    // =========================================================================
    // JOINS
    // =========================================================================

    public function test_inner_join(): void
    {
        $sql = $this->builder()->from('users')
            ->join('posts', 'users.id', '=', 'posts.user_id')
            ->toSql();
        $this->assertStringContainsString('INNER JOIN `posts` ON `users`.`id` = `posts`.`user_id`', $sql);
    }

    public function test_left_join(): void
    {
        $sql = $this->builder()->from('users')
            ->leftJoin('profiles', 'users.id', '=', 'profiles.user_id')
            ->toSql();
        $this->assertStringContainsString('LEFT JOIN `profiles` ON `users`.`id` = `profiles`.`user_id`', $sql);
    }

    public function test_right_join(): void
    {
        $sql = $this->builder()->from('orders')
            ->rightJoin('users', 'orders.user_id', '=', 'users.id')
            ->toSql();
        $this->assertStringContainsString('RIGHT JOIN `users` ON `orders`.`user_id` = `users`.`id`', $sql);
    }

    public function test_cross_join(): void
    {
        $sql = $this->builder()->from('colors')
            ->crossJoin('sizes')
            ->toSql();
        $this->assertStringContainsString('CROSS JOIN `sizes`', $sql);
    }

    public function test_multiple_joins(): void
    {
        $sql = $this->builder()->from('users')
            ->join('posts', 'users.id', '=', 'posts.user_id')
            ->leftJoin('comments', 'posts.id', '=', 'comments.post_id')
            ->toSql();
        $this->assertStringContainsString('INNER JOIN `posts`', $sql);
        $this->assertStringContainsString('LEFT JOIN `comments`', $sql);
    }

    // =========================================================================
    // GROUP BY / HAVING
    // =========================================================================

    public function test_group_by(): void
    {
        $sql = $this->builder()->from('orders')->groupBy('status')->toSql();
        $this->assertStringContainsString('GROUP BY `status`', $sql);
    }

    public function test_group_by_multiple(): void
    {
        $sql = $this->builder()->from('orders')->groupBy('status', 'region')->toSql();
        $this->assertStringContainsString('GROUP BY `status`, `region`', $sql);
    }

    public function test_having(): void
    {
        $q = $this->builder()->from('orders')
            ->groupBy('status')
            ->having('total', '>', 100);
        $this->assertStringContainsString('HAVING `total` > ?', $q->toSql());
    }

    public function test_having_two_args(): void
    {
        $q = $this->builder()->from('orders')
            ->groupBy('status')
            ->having('cnt', 5);
        $this->assertStringContainsString('HAVING `cnt` = ?', $q->toSql());
    }

    // =========================================================================
    // ORDER BY
    // =========================================================================

    public function test_order_by_asc(): void
    {
        $sql = $this->builder()->from('users')->orderBy('name')->toSql();
        $this->assertStringContainsString('ORDER BY `name` ASC', $sql);
    }

    public function test_order_by_desc(): void
    {
        $sql = $this->builder()->from('users')->orderByDesc('created_at')->toSql();
        $this->assertStringContainsString('ORDER BY `created_at` DESC', $sql);
    }

    public function test_order_by_multiple(): void
    {
        $sql = $this->builder()->from('users')
            ->orderBy('name')
            ->orderByDesc('created_at')
            ->toSql();
        $this->assertStringContainsString('ORDER BY `name` ASC, `created_at` DESC', $sql);
    }

    public function test_latest(): void
    {
        $sql = $this->builder()->from('users')->latest()->toSql();
        $this->assertStringContainsString('ORDER BY `created_at` DESC', $sql);
    }

    public function test_oldest(): void
    {
        $sql = $this->builder()->from('users')->oldest()->toSql();
        $this->assertStringContainsString('ORDER BY `created_at` ASC', $sql);
    }

    public function test_order_by_raw(): void
    {
        $sql = $this->builder()->from('users')
            ->orderByRaw('FIELD(status, "active", "pending", "banned")')
            ->toSql();
        $this->assertStringContainsString('ORDER BY FIELD(status, "active", "pending", "banned")', $sql);
    }

    // =========================================================================
    // LIMIT / OFFSET
    // =========================================================================

    public function test_limit(): void
    {
        $sql = $this->builder()->from('users')->limit(10)->toSql();
        $this->assertStringContainsString('LIMIT 10', $sql);
    }

    public function test_offset(): void
    {
        $sql = $this->builder()->from('users')->limit(10)->offset(20)->toSql();
        $this->assertStringContainsString('LIMIT 10', $sql);
        $this->assertStringContainsString('OFFSET 20', $sql);
    }

    public function test_take_alias(): void
    {
        $sql = $this->builder()->from('users')->take(5)->toSql();
        $this->assertStringContainsString('LIMIT 5', $sql);
    }

    public function test_skip_alias(): void
    {
        $sql = $this->builder()->from('users')->take(10)->skip(30)->toSql();
        $this->assertStringContainsString('OFFSET 30', $sql);
    }

    // =========================================================================
    // INSERT
    // =========================================================================

    public function test_compile_insert_single(): void
    {
        $q = $this->builder()->from('users');
        $sql = $this->grammar->compileInsert($q, ['name' => 'John', 'email' => 'john@example.com']);
        $this->assertStringContainsString('INSERT INTO `users`', $sql);
        $this->assertStringContainsString('(`name`, `email`)', $sql);
        $this->assertStringContainsString('(?, ?)', $sql);
    }

    public function test_compile_insert_multiple(): void
    {
        $q = $this->builder()->from('users');
        $sql = $this->grammar->compileInsert($q, [
            ['name' => 'John', 'email' => 'john@test.com'],
            ['name' => 'Jane', 'email' => 'jane@test.com'],
        ]);
        $this->assertStringContainsString('INSERT INTO `users`', $sql);
        $this->assertStringContainsString('(?, ?), (?, ?)', $sql);
    }

    public function test_compile_insert_empty(): void
    {
        // MySQL doesn't support 'DEFAULT VALUES' — the standard-SQL idiom
        // doesn't parse. Use '() VALUES ()' instead, which produces the
        // same all-defaults insert and IS valid MySQL.
        $q = $this->builder()->from('users');
        $sql = $this->grammar->compileInsert($q, []);
        $this->assertSame('INSERT INTO `users` () VALUES ()', $sql);
    }

    // =========================================================================
    // UPDATE
    // =========================================================================

    public function test_compile_update(): void
    {
        $q = $this->builder()->from('users')->where('id', 1);
        $sql = $this->grammar->compileUpdate($q, ['name' => 'John', 'email' => 'new@test.com']);
        $this->assertStringContainsString('UPDATE `users` SET `name` = ?, `email` = ?', $sql);
        $this->assertStringContainsString('WHERE `id` = ?', $sql);
    }

    public function test_compile_update_no_wheres(): void
    {
        $q = $this->builder()->from('users');
        $sql = $this->grammar->compileUpdate($q, ['status' => 'inactive']);
        $this->assertSame('UPDATE `users` SET `status` = ?', $sql);
    }

    public function test_compile_update_with_raw(): void
    {
        $q = $this->builder()->from('users')->where('id', 1);
        $sql = $this->grammar->compileUpdate($q, [
            'views' => new RawExpression('views + 1'),
        ]);
        $this->assertStringContainsString('`views` = views + 1', $sql);
    }

    // =========================================================================
    // DELETE
    // =========================================================================

    public function test_compile_delete(): void
    {
        $q = $this->builder()->from('users')->where('id', 1);
        $sql = $this->grammar->compileDelete($q);
        $this->assertSame('DELETE FROM `users` WHERE `id` = ?', $sql);
    }

    public function test_compile_delete_no_wheres(): void
    {
        $q = $this->builder()->from('users');
        $sql = $this->grammar->compileDelete($q);
        $this->assertSame('DELETE FROM `users`', $sql);
    }

    // =========================================================================
    // EXISTS
    // =========================================================================

    public function test_compile_exists(): void
    {
        $q = $this->builder()->from('users')->where('status', 'active');
        $sql = $this->grammar->compileExists($q);
        $this->assertStringContainsString('SELECT EXISTS(', $sql);
        $this->assertStringContainsString('AS `exists`', $sql);
    }

    // =========================================================================
    // AGGREGATE
    // =========================================================================

    public function test_compile_aggregate_count(): void
    {
        $q = $this->builder()->from('users');
        $sql = $this->grammar->compileAggregate($q, 'COUNT', '*');
        $this->assertStringContainsString('SELECT COUNT(*) AS aggregate FROM `users`', $sql);
    }

    public function test_compile_aggregate_sum(): void
    {
        $q = $this->builder()->from('orders');
        $sql = $this->grammar->compileAggregate($q, 'SUM', 'total');
        $this->assertStringContainsString('SELECT SUM(`total`) AS aggregate FROM `orders`', $sql);
    }

    public function test_compile_aggregate_with_wheres(): void
    {
        $q = $this->builder()->from('orders')->where('status', 'completed');
        $sql = $this->grammar->compileAggregate($q, 'AVG', 'total');
        $this->assertStringContainsString('AVG(`total`) AS aggregate', $sql);
        $this->assertStringContainsString('WHERE `status` = ?', $sql);
    }

    public function test_compile_aggregate_with_group(): void
    {
        $q = $this->builder()->from('orders')->groupBy('region');
        $sql = $this->grammar->compileAggregate($q, 'COUNT', '*');
        $this->assertStringContainsString('GROUP BY `region`', $sql);
    }

    // =========================================================================
    // TRUNCATE
    // =========================================================================

    public function test_compile_truncate(): void
    {
        $this->assertSame('TRUNCATE TABLE `users`', $this->grammar->compileTruncate('users'));
    }

    // =========================================================================
    // Full Complex Query
    // =========================================================================

    public function test_complex_select(): void
    {
        $q = $this->builder()
            ->from('users')
            ->select('users.id', 'users.name', new RawExpression('COUNT(orders.id) as order_count'))
            ->distinct()
            ->leftJoin('orders', 'users.id', '=', 'orders.user_id')
            ->where('users.status', 'active')
            ->whereNotNull('users.email')
            ->groupBy('users.id')
            ->having('order_count', '>', 0)
            ->orderByDesc('order_count')
            ->limit(10)
            ->offset(0);

        $sql = $q->toSql();

        $this->assertStringContainsString('SELECT DISTINCT', $sql);
        $this->assertStringContainsString('COUNT(orders.id) as order_count', $sql);
        $this->assertStringContainsString('FROM `users`', $sql);
        $this->assertStringContainsString('LEFT JOIN `orders` ON `users`.`id` = `orders`.`user_id`', $sql);
        $this->assertStringContainsString('WHERE `users`.`status` = ?', $sql);
        $this->assertStringContainsString('`users`.`email` IS NOT NULL', $sql);
        $this->assertStringContainsString('GROUP BY `users`.`id`', $sql);
        $this->assertStringContainsString('HAVING `order_count` > ?', $sql);
        $this->assertStringContainsString('ORDER BY `order_count` DESC', $sql);
        $this->assertStringContainsString('LIMIT 10', $sql);
    }

    public function test_complex_bindings_order(): void
    {
        $q = $this->builder()
            ->from('orders')
            ->where('status', 'completed')
            ->whereIn('region', ['US', 'EU'])
            ->whereBetween('total', [100, 500])
            ->having('cnt', '>', 5);

        $bindings = $q->getBindings();
        $this->assertSame(['completed', 'US', 'EU', 100, 500, 5], $bindings);
    }

    // =========================================================================
    // WHEN (Conditional Builder)
    // =========================================================================

    public function test_when_true_applies(): void
    {
        $sql = $this->builder()->from('users')
            ->when(true, fn($q) => $q->where('active', 1))
            ->toSql();
        $this->assertStringContainsString('WHERE `active` = ?', $sql);
    }

    public function test_when_false_skips(): void
    {
        $sql = $this->builder()->from('users')
            ->when(false, fn($q) => $q->where('active', 1))
            ->toSql();
        $this->assertStringNotContainsString('WHERE', $sql);
    }

    public function test_when_false_runs_default(): void
    {
        $sql = $this->builder()->from('users')
            ->when(
                false,
                fn($q) => $q->where('role', 'admin'),
                fn($q) => $q->where('role', 'user')
            )
            ->toSql();
        $this->assertStringContainsString('`role` = ?', $sql);
    }

    // =========================================================================
    // Schema Introspection SQL
    // =========================================================================

    public function test_compile_tables(): void
    {
        $sql = $this->grammar->compileTables();
        $this->assertStringContainsString('information_schema.tables', $sql);
        $this->assertStringContainsString('DATABASE()', $sql);
    }

    public function test_compile_views(): void
    {
        $sql = $this->grammar->compileViews();
        $this->assertStringContainsString('information_schema.views', $sql);
    }

    public function test_compile_column_listing(): void
    {
        $sql = $this->grammar->compileColumnListing();
        $this->assertStringContainsString('column_name', $sql);
        $this->assertStringContainsString('table_name = ?', $sql);
    }

    public function test_compile_indexes(): void
    {
        $sql = $this->grammar->compileIndexes();
        $this->assertStringContainsString('information_schema.statistics', $sql);
    }

    public function test_compile_foreign_keys(): void
    {
        $sql = $this->grammar->compileForeignKeys();
        $this->assertStringContainsString('key_column_usage', $sql);
        $this->assertStringContainsString('referenced_table_name', $sql);
    }

    public function test_compile_has_table(): void
    {
        $sql = $this->grammar->compileHasTable();
        $this->assertStringContainsString('COUNT(*)', $sql);
        $this->assertStringContainsString('table_name = ?', $sql);
    }

    public function test_compile_has_column(): void
    {
        $sql = $this->grammar->compileHasColumn();
        $this->assertStringContainsString('column_name = ?', $sql);
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function test_empty_wheres_returns_empty(): void
    {
        $q = $this->builder()->from('users');
        $this->assertSame('', $this->grammar->compileWheres($q));
    }

    public function test_no_joins_returns_empty_string(): void
    {
        $sql = $this->builder()->from('users')->toSql();
        $this->assertStringNotContainsString('JOIN', $sql);
    }

    public function test_no_group_no_having_no_order(): void
    {
        $sql = $this->builder()->from('users')->toSql();
        $this->assertStringNotContainsString('GROUP BY', $sql);
        $this->assertStringNotContainsString('HAVING', $sql);
        $this->assertStringNotContainsString('ORDER BY', $sql);
    }

    public function test_upsert_throws_on_base_grammar(): void
    {
        $this->expectException(\RuntimeException::class);
        $q = $this->builder()->from('users');
        $this->grammar->compileUpsert($q, [], [], []);
    }

    public function test_lock_throws_on_base_grammar(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->grammar->compileLock('shared');
    }
}
