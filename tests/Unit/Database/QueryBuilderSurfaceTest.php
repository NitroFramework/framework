<?php

namespace Tests\Unit\Database;

use Nitro\Database\Connection;
use Nitro\Database\Query\Grammar\Grammar;
use Nitro\Database\Query\Grammar\MySqlGrammar;
use Nitro\Database\Query\Grammar\SqliteGrammar;
use Nitro\Database\Query\JoinClause;
use Nitro\Database\Query\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Compilation of the where, having, join, union and paging clauses.
 */
class QueryBuilderSurfaceTest extends TestCase
{
    private function builder(?Grammar $grammar = null): QueryBuilder
    {
        return new QueryBuilder($this->createMock(Connection::class), $grammar ?? new Grammar());
    }

    // ─── Nesting ──────────────────────────────────────────

    public function test_nested_group_is_parenthesised(): void
    {
        $query = $this->builder()->from('users')
            ->where('status', 'active')
            ->where(function (QueryBuilder $group): void {
                $group->where('role', 'admin')->orWhere('role', 'owner');
            });

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? AND (`role` = ? OR `role` = ?)',
            $query->toSql()
        );
        $this->assertSame(['active', 'admin', 'owner'], $query->getBindings());
    }

    public function test_empty_group_adds_nothing(): void
    {
        $query = $this->builder()->from('users')
            ->where('status', 'active')
            ->where(function (QueryBuilder $group): void {
                // no conditions
            });

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ?', $query->toSql());
    }

    public function test_where_not_negates_a_group(): void
    {
        $query = $this->builder()->from('users')
            ->whereNot(function (QueryBuilder $group): void {
                $group->where('banned', 1)->orWhere('deleted', 1);
            });

        $this->assertSame(
            'SELECT * FROM `users` WHERE NOT (`banned` = ? OR `deleted` = ?)',
            $query->toSql()
        );
    }

    public function test_where_not_negates_a_single_condition(): void
    {
        $query = $this->builder()->from('users')->whereNot('role', 'admin');

        $this->assertSame('SELECT * FROM `users` WHERE NOT (`role` = ?)', $query->toSql());
        $this->assertSame(['admin'], $query->getBindings());
    }

    public function test_array_of_conditions(): void
    {
        $query = $this->builder()->from('users')->where(['status' => 'active', 'role' => 'admin']);

        $this->assertSame(
            'SELECT * FROM `users` WHERE (`status` = ? AND `role` = ?)',
            $query->toSql()
        );
        $this->assertSame(['active', 'admin'], $query->getBindings());
    }

    public function test_array_of_triples(): void
    {
        $query = $this->builder()->from('users')->where([
            ['votes', '>=', 100],
            ['age', '<', 50],
        ]);

        $this->assertSame(
            'SELECT * FROM `users` WHERE (`votes` >= ? AND `age` < ?)',
            $query->toSql()
        );
        $this->assertSame([100, 50], $query->getBindings());
    }

    // ─── IN ───────────────────────────────────────────────

    public function test_empty_where_in_matches_nothing(): void
    {
        $query = $this->builder()->from('users')->whereIn('id', []);

        $this->assertSame('SELECT * FROM `users` WHERE 0 = 1', $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    public function test_empty_where_not_in_excludes_nothing(): void
    {
        $query = $this->builder()->from('users')->whereNotIn('id', []);

        $this->assertSame('SELECT * FROM `users` WHERE 1 = 1', $query->toSql());
    }

    public function test_where_in_subquery(): void
    {
        $query = $this->builder()->from('users')->whereIn('id', function (QueryBuilder $sub): void {
            $sub->from('bans')->select('user_id')->where('active', 1);
        });

        $this->assertSame(
            'SELECT * FROM `users` WHERE `id` IN (SELECT `user_id` FROM `bans` WHERE `active` = ?)',
            $query->toSql()
        );
        $this->assertSame([1], $query->getBindings());
    }

    public function test_where_integer_in_raw_writes_numbers_into_the_sql(): void
    {
        $query = $this->builder()->from('users')->whereIntegerInRaw('id', ['3', 4, '5x']);

        $this->assertSame('SELECT * FROM `users` WHERE `id` IN (3, 4, 5)', $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    // ─── BETWEEN ──────────────────────────────────────────

    public function test_where_not_between(): void
    {
        $query = $this->builder()->from('users')->whereNotBetween('age', [18, 30]);

        $this->assertSame('SELECT * FROM `users` WHERE `age` NOT BETWEEN ? AND ?', $query->toSql());
        $this->assertSame([18, 30], $query->getBindings());
    }

    public function test_where_between_columns(): void
    {
        $query = $this->builder()->from('bookings')->whereBetweenColumns('date', ['starts_at', 'ends_at']);

        $this->assertSame(
            'SELECT * FROM `bookings` WHERE `date` BETWEEN `starts_at` AND `ends_at`',
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
    }

    public function test_where_between_rejects_the_wrong_number_of_values(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->builder()->from('users')->whereBetween('age', [18]);
    }

    // ─── Dates ────────────────────────────────────────────

    public function test_where_date_parts(): void
    {
        $query = $this->builder()->from('posts')
            ->whereYear('published_at', 2026)
            ->whereMonth('published_at', 9)
            ->whereDay('published_at', 3)
            ->whereTime('published_at', '>=', '08:00:00');

        $this->assertSame(
            'SELECT * FROM `posts` WHERE YEAR(`published_at`) = ? AND MONTH(`published_at`) = ?'
                . ' AND DAY(`published_at`) = ? AND TIME(`published_at`) >= ?',
            $query->toSql()
        );
        $this->assertSame([2026, '09', '03', '08:00:00'], $query->getBindings());
    }

    public function test_sqlite_reads_date_parts_with_strftime(): void
    {
        $query = $this->builder(new SqliteGrammar())->from('posts')->whereYear('published_at', 2026);

        $this->assertSame(
            "SELECT * FROM `posts` WHERE strftime('%Y', `published_at`) = ?",
            $query->toSql()
        );
    }

    public function test_where_date_accepts_a_date_object(): void
    {
        $query = $this->builder()->from('posts')->whereDate('published_at', new \DateTimeImmutable('2026-09-03 11:12:13'));

        $this->assertSame('SELECT * FROM `posts` WHERE DATE(`published_at`) = ?', $query->toSql());
        $this->assertSame(['2026-09-03'], $query->getBindings());
    }

    /** An operator that is not one goes nowhere near the SQL. */
    public function test_where_date_validates_its_operator(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->builder()->from('posts')->whereDate('published_at', '= 1 OR 1', '2026-09-03')->toSql();
    }

    // ─── LIKE ─────────────────────────────────────────────

    public function test_where_like(): void
    {
        $query = $this->builder()->from('users')->whereLike('name', 'Ada%');

        $this->assertSame('SELECT * FROM `users` WHERE `name` LIKE ?', $query->toSql());
        $this->assertSame(['Ada%'], $query->getBindings());
    }

    public function test_where_not_like_or(): void
    {
        $query = $this->builder()->from('users')
            ->whereLike('name', 'A%')
            ->orWhereNotLike('email', '%@example.com');

        $this->assertSame(
            'SELECT * FROM `users` WHERE `name` LIKE ? OR `email` NOT LIKE ?',
            $query->toSql()
        );
    }

    public function test_mysql_case_sensitive_like_uses_binary(): void
    {
        $query = $this->builder(new MySqlGrammar())->from('users')->whereLike('name', 'Ada%', true);

        $this->assertSame('SELECT * FROM `users` WHERE `name` LIKE BINARY ?', $query->toSql());
    }

    public function test_sqlite_case_sensitive_like_uses_glob_and_translates_the_pattern(): void
    {
        $query = $this->builder(new SqliteGrammar())->from('users')->whereLike('name', 'Ada%_x');

        $this->assertSame('SELECT * FROM `users` WHERE `name` LIKE ?', $query->toSql());

        $sensitive = $this->builder(new SqliteGrammar())->from('users')->whereLike('name', 'Ada%_x', true);

        $this->assertSame('SELECT * FROM `users` WHERE `name` GLOB ?', $sensitive->toSql());
        $this->assertSame(['Ada*?x'], $sensitive->getBindings());
    }

    public function test_base_grammar_refuses_case_sensitive_like(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->builder()->from('users')->whereLike('name', 'Ada%', true)->toSql();
    }

    // ─── Column sets ──────────────────────────────────────

    public function test_where_any(): void
    {
        $query = $this->builder()->from('users')->whereAny(['name', 'email'], 'like', '%ada%');

        $this->assertSame(
            'SELECT * FROM `users` WHERE (`name` LIKE ? OR `email` LIKE ?)',
            $query->toSql()
        );
        $this->assertSame(['%ada%', '%ada%'], $query->getBindings());
    }

    public function test_where_all(): void
    {
        $query = $this->builder()->from('users')->whereAll(['first', 'last'], '!=', '');

        $this->assertSame(
            'SELECT * FROM `users` WHERE (`first` != ? AND `last` != ?)',
            $query->toSql()
        );
    }

    public function test_where_none(): void
    {
        $query = $this->builder()->from('users')->whereNone(['name', 'email'], 'like', '%spam%');

        $this->assertSame(
            'SELECT * FROM `users` WHERE NOT (`name` LIKE ? OR `email` LIKE ?)',
            $query->toSql()
        );
    }

    // ─── Exists and sub-selects ───────────────────────────

    public function test_where_not_exists(): void
    {
        $query = $this->builder()->from('users')->whereNotExists(function (QueryBuilder $sub): void {
            $sub->from('orders')->whereColumn('orders.user_id', '=', 'users.id');
        });

        $this->assertSame(
            'SELECT * FROM `users` WHERE NOT EXISTS (SELECT * FROM `orders` WHERE `orders`.`user_id` = `users`.`id`)',
            $query->toSql()
        );
    }

    public function test_where_against_a_sub_select(): void
    {
        $query = $this->builder()->from('users')->where('id', '=', function (QueryBuilder $sub): void {
            $sub->from('sessions')->selectRaw('MAX(user_id)');
        });

        $this->assertSame(
            'SELECT * FROM `users` WHERE `id` = (SELECT MAX(user_id) FROM `sessions`)',
            $query->toSql()
        );
    }

    public function test_select_sub(): void
    {
        $query = $this->builder()->from('users')->selectSub(function (QueryBuilder $sub): void {
            $sub->from('orders')->selectRaw('COUNT(*)')->whereColumn('orders.user_id', '=', 'users.id');
        }, 'orders_count');

        $this->assertSame(
            'SELECT `id`, (SELECT COUNT(*) FROM `orders` WHERE `orders`.`user_id` = `users`.`id`) AS `orders_count`'
                . ' FROM `users`',
            $query->select('id')->selectSub(function (QueryBuilder $sub): void {
                $sub->from('orders')->selectRaw('COUNT(*)')->whereColumn('orders.user_id', '=', 'users.id');
            }, 'orders_count')->toSql()
        );
    }

    public function test_from_sub(): void
    {
        $query = $this->builder()->fromSub(function (QueryBuilder $sub): void {
            $sub->from('orders')->where('total', '>', 100);
        }, 'big_orders')->where('id', 1);

        $this->assertSame(
            'SELECT * FROM (SELECT * FROM `orders` WHERE `total` > ?) AS `big_orders` WHERE `id` = ?',
            $query->toSql()
        );
        $this->assertSame([100, 1], $query->getBindings());
    }

    // ─── Joins ────────────────────────────────────────────

    public function test_simple_join_is_unchanged(): void
    {
        $query = $this->builder()->from('users')->join('posts', 'posts.user_id', '=', 'users.id');

        $this->assertSame(
            'SELECT * FROM `users` INNER JOIN `posts` ON `posts`.`user_id` = `users`.`id`',
            $query->toSql()
        );
    }

    public function test_join_with_a_callback(): void
    {
        $query = $this->builder()->from('users')->leftJoin('posts', function (JoinClause $join): void {
            $join->on('posts.user_id', '=', 'users.id')
                 ->where('posts.published', '=', 1);
        });

        $this->assertSame(
            'SELECT * FROM `users` LEFT JOIN `posts` ON `posts`.`user_id` = `users`.`id` AND `posts`.`published` = ?',
            $query->toSql()
        );
        $this->assertSame([1], $query->getBindings());
    }

    public function test_join_conditions_can_be_ored(): void
    {
        $query = $this->builder()->from('users')->join('contacts', function (JoinClause $join): void {
            $join->on('contacts.user_id', '=', 'users.id')
                 ->orOn('contacts.email', '=', 'users.email');
        });

        $this->assertSame(
            'SELECT * FROM `users` INNER JOIN `contacts` ON `contacts`.`user_id` = `users`.`id`'
                . ' OR `contacts`.`email` = `users`.`email`',
            $query->toSql()
        );
    }

    public function test_join_bindings_come_before_where_bindings(): void
    {
        $query = $this->builder()->from('users')
            ->join('posts', function (JoinClause $join): void {
                $join->on('posts.user_id', '=', 'users.id')->where('posts.type', 'article');
            })
            ->where('users.active', 1);

        $this->assertSame(['article', 1], $query->getBindings());
    }

    public function test_join_sub(): void
    {
        $query = $this->builder()->from('users')->joinSub(
            function (QueryBuilder $sub): void {
                $sub->from('orders')->selectRaw('user_id, COUNT(*) AS total')->groupBy('user_id');
            },
            'order_counts',
            'order_counts.user_id',
            '=',
            'users.id'
        );

        $this->assertSame(
            'SELECT * FROM `users` INNER JOIN (SELECT user_id, COUNT(*) AS total FROM `orders` GROUP BY `user_id`)'
                . ' AS `order_counts` ON `order_counts`.`user_id` = `users`.`id`',
            $query->toSql()
        );
    }

    public function test_cross_join_without_conditions(): void
    {
        $query = $this->builder()->from('sizes')->crossJoin('colours');

        $this->assertSame('SELECT * FROM `sizes` CROSS JOIN `colours`', $query->toSql());
    }

    // ─── Having ───────────────────────────────────────────

    public function test_having_keeps_its_boolean(): void
    {
        $query = $this->builder()->from('orders')
            ->groupBy('user_id')
            ->having('total', '>', 100)
            ->orHaving('count', '>', 5);

        $this->assertSame(
            'SELECT * FROM `orders` GROUP BY `user_id` HAVING `total` > ? OR `count` > ?',
            $query->toSql()
        );
        $this->assertSame([100, 5], $query->getBindings());
    }

    public function test_having_raw_and_between(): void
    {
        $query = $this->builder()->from('orders')
            ->groupBy('user_id')
            ->havingBetween('total', [10, 20])
            ->havingRaw('SUM(total) > ?', [500]);

        $this->assertSame(
            'SELECT * FROM `orders` GROUP BY `user_id` HAVING `total` BETWEEN ? AND ? AND SUM(total) > ?',
            $query->toSql()
        );
        $this->assertSame([10, 20, 500], $query->getBindings());
    }

    public function test_having_nested(): void
    {
        $query = $this->builder()->from('orders')
            ->groupBy('user_id')
            ->having(function (QueryBuilder $group): void {
                $group->having('total', '>', 100)->orHaving('total', '<', 10);
            });

        $this->assertSame(
            'SELECT * FROM `orders` GROUP BY `user_id` HAVING (`total` > ? OR `total` < ?)',
            $query->toSql()
        );
    }

    public function test_group_by_raw(): void
    {
        $query = $this->builder()->from('orders')->groupByRaw('YEAR(created_at), status');

        $this->assertSame('SELECT * FROM `orders` GROUP BY YEAR(created_at), status', $query->toSql());
    }

    // ─── Ordering and paging ──────────────────────────────

    public function test_reorder_clears_orders_and_their_bindings(): void
    {
        $query = $this->builder()->from('users')
            ->orderByRaw('FIELD(status, ?)', ['active'])
            ->where('id', 1)
            ->reorder('name');

        $this->assertSame('SELECT * FROM `users` WHERE `id` = ? ORDER BY `name` ASC', $query->toSql());
        $this->assertSame([1], $query->getBindings());
    }

    public function test_for_page(): void
    {
        $query = $this->builder()->from('users')->forPage(3, 10);

        $this->assertSame('SELECT * FROM `users` LIMIT 10 OFFSET 20', $query->toSql());
    }

    public function test_for_page_after_id(): void
    {
        $query = $this->builder()->from('users')->forPageAfterId(5, 100);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `id` > ? ORDER BY `id` ASC LIMIT 5',
            $query->toSql()
        );
        $this->assertSame([100], $query->getBindings());
    }

    public function test_in_order_of_compiles_to_a_case(): void
    {
        $query = $this->builder()->from('users')->inOrderOf('status', ['active', 'pending']);

        $this->assertSame(
            'SELECT * FROM `users` ORDER BY CASE WHEN `status` = ? THEN 0 WHEN `status` = ? THEN 1 ELSE 2 END',
            $query->toSql()
        );
        $this->assertSame(['active', 'pending'], $query->getBindings());
    }

    public function test_in_random_order(): void
    {
        $this->assertStringContainsString(
            'ORDER BY RANDOM()',
            $this->builder()->from('users')->inRandomOrder()->toSql()
        );

        $this->assertStringContainsString(
            'ORDER BY RAND(7)',
            $this->builder(new MySqlGrammar())->from('users')->inRandomOrder(7)->toSql()
        );
    }

    // ─── Unions ───────────────────────────────────────────

    public function test_union(): void
    {
        $first = $this->builder()->from('users')->where('active', 1);
        $second = $this->builder()->from('archived_users')->where('active', 0);

        $first->union($second)->orderBy('id')->limit(10);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `active` = ?'
                . ' UNION SELECT * FROM `archived_users` WHERE `active` = ?'
                . ' ORDER BY `id` ASC LIMIT 10',
            $first->toSql()
        );
        $this->assertSame([1, 0], $first->getBindings());
    }

    public function test_union_all(): void
    {
        $first = $this->builder()->from('a');

        $first->unionAll($this->builder()->from('b'));

        $this->assertSame('SELECT * FROM `a` UNION ALL SELECT * FROM `b`', $first->toSql());
    }

    // ─── Index hints ──────────────────────────────────────

    public function test_index_hints_are_driver_specific(): void
    {
        $this->assertSame(
            'SELECT * FROM `users` FORCE INDEX (`users_email_index`)',
            $this->builder(new MySqlGrammar())->from('users')->forceIndex('users_email_index')->toSql()
        );

        $this->assertSame(
            'SELECT * FROM `users` INDEXED BY `users_email_index`',
            $this->builder(new SqliteGrammar())->from('users')->useIndex('users_email_index')->toSql()
        );

        // A grammar with no syntax for one drops it rather than failing.
        $this->assertSame(
            'SELECT * FROM `users`',
            $this->builder()->from('users')->forceIndex('users_email_index')->toSql()
        );
    }

    // ─── JSON ─────────────────────────────────────────────

    public function test_json_path_becomes_an_accessor(): void
    {
        $this->assertSame(
            "SELECT * FROM `users` WHERE json_unquote(json_extract(`options`, '$.\"theme\"')) = ?",
            $this->builder(new MySqlGrammar())->from('users')->where('options->theme', 'dark')->toSql()
        );

        $this->assertSame(
            "SELECT * FROM `users` WHERE json_extract(`options`, '$.\"theme\"') = ?",
            $this->builder(new SqliteGrammar())->from('users')->where('options->theme', 'dark')->toSql()
        );
    }

    public function test_json_path_handles_array_offsets(): void
    {
        $this->assertSame(
            "SELECT * FROM `users` WHERE json_unquote(json_extract(`options`, '$.\"tags\"[0]')) = ?",
            $this->builder(new MySqlGrammar())->from('users')->where('options->tags->0', 'php')->toSql()
        );
    }

    public function test_where_json_contains(): void
    {
        $mysql = $this->builder(new MySqlGrammar())->from('users')
            ->whereJsonContains('options->languages', 'en');

        $this->assertSame(
            "SELECT * FROM `users` WHERE json_contains(`options`, ?, '$.\"languages\"')",
            $mysql->toSql()
        );
        $this->assertSame(['"en"'], $mysql->getBindings());

        $sqlite = $this->builder(new SqliteGrammar())->from('users')
            ->whereJsonContains('options->languages', 'en');

        $this->assertStringContainsString('json_each', $sqlite->toSql());
        $this->assertSame(['en'], $sqlite->getBindings(), 'SQLite compares the value, not its JSON form');
    }

    public function test_where_json_doesnt_contain(): void
    {
        $query = $this->builder(new MySqlGrammar())->from('users')
            ->whereJsonDoesntContain('options->languages', 'en');

        $this->assertStringStartsWith('SELECT * FROM `users` WHERE NOT json_contains(', $query->toSql());
    }

    public function test_where_json_length(): void
    {
        $query = $this->builder(new MySqlGrammar())->from('users')
            ->whereJsonLength('options->languages', '>', 1);

        $this->assertSame(
            "SELECT * FROM `users` WHERE json_length(`options`, '$.\"languages\"') > ?",
            $query->toSql()
        );
        $this->assertSame([1], $query->getBindings());
    }

    public function test_where_json_length_two_argument_form(): void
    {
        $query = $this->builder(new MySqlGrammar())->from('users')
            ->whereJsonLength('options->languages', 2);

        $this->assertStringEndsWith('> ?', str_replace('=', '>', $query->toSql()));
        $this->assertSame([2], $query->getBindings());
    }

    public function test_where_json_contains_key(): void
    {
        $this->assertSame(
            "SELECT * FROM `users` WHERE ifnull(json_contains_path(`options`, 'one', '$.\"theme\"'), 0)",
            $this->builder(new MySqlGrammar())->from('users')->whereJsonContainsKey('options->theme')->toSql()
        );

        $this->assertSame(
            "SELECT * FROM `users` WHERE json_type(`options`, '$.\"theme\"') IS NULL",
            $this->builder(new SqliteGrammar())->from('users')->whereJsonDoesntContainKey('options->theme')->toSql()
        );
    }

    public function test_where_json_overlaps(): void
    {
        $query = $this->builder(new MySqlGrammar())->from('users')
            ->whereJsonOverlaps('options->tags', ['php', 'sql']);

        $this->assertSame(
            "SELECT * FROM `users` WHERE json_overlaps(json_extract(`options`, '$.\"tags\"'), ?)",
            $query->toSql()
        );
        $this->assertSame(['["php","sql"]'], $query->getBindings());
    }

    public function test_where_full_text(): void
    {
        $query = $this->builder(new MySqlGrammar())->from('posts')
            ->whereFullText(['title', 'body'], 'cats', ['mode' => 'boolean']);

        $this->assertSame(
            'SELECT * FROM `posts` WHERE MATCH (`title`, `body`) AGAINST (? IN BOOLEAN MODE)',
            $query->toSql()
        );
        $this->assertSame(['cats'], $query->getBindings());
    }

    public function test_full_text_is_refused_where_it_is_not_supported(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->builder(new SqliteGrammar())->from('posts')->whereFullText('title', 'cats')->toSql();
    }

    // ─── Dynamic where ────────────────────────────────────

    public function test_dynamic_where(): void
    {
        $query = $this->builder()->from('users')->whereNameAndEmail('Ada', 'ada@example.com');

        $this->assertSame(
            'SELECT * FROM `users` WHERE `name` = ? AND `email` = ?',
            $query->toSql()
        );
        $this->assertSame(['Ada', 'ada@example.com'], $query->getBindings());
    }

    public function test_dynamic_where_with_or_and_compound_columns(): void
    {
        $query = $this->builder()->from('users')->whereFirstNameOrLastName('Ada', 'Byron');

        $this->assertSame(
            'SELECT * FROM `users` WHERE `first_name` = ? OR `last_name` = ?',
            $query->toSql()
        );
    }

    public function test_dynamic_where_needs_a_value_for_every_segment(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->builder()->from('users')->whereNameAndEmail('Ada');
    }

    public function test_unknown_method_still_raises(): void
    {
        $this->expectException(\BadMethodCallException::class);

        $this->builder()->from('users')->frobnicate();
    }

    // ─── Bindings and inspection ──────────────────────────

    public function test_add_and_set_bindings(): void
    {
        $query = $this->builder()->from('users')->where('id', 1);

        $query->addBinding(2, 'where');
        $this->assertSame([1, 2], $query->getBindings());

        $query->setBindings([9], 'where');
        $this->assertSame([9], $query->getBindings());
    }

    public function test_binding_type_is_checked(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->builder()->addBinding(1, 'nonsense');
    }

    public function test_merge_bindings(): void
    {
        $source = $this->builder()->from('a')->where('x', 1);
        $target = $this->builder()->from('b')->where('y', 2);

        $target->mergeBindings($source);

        $this->assertSame([2, 1], $target->getBindings());
    }

    public function test_clean_bindings_drops_raw_expressions(): void
    {
        $query = $this->builder();

        $this->assertSame(
            [1, 'a'],
            $query->cleanBindings([1, $query->raw('NOW()'), 'a'])
        );
    }

    public function test_clone_without(): void
    {
        $query = $this->builder()->from('users')->where('id', 1)->orderBy('name')->limit(5);

        $clone = $query->cloneWithout(['orders', 'limitValue']);

        $this->assertSame('SELECT * FROM `users` WHERE `id` = ?', $clone->toSql());
        $this->assertStringContainsString('ORDER BY', $query->toSql(), 'the original is untouched');
    }

    public function test_to_raw_sql_writes_the_bindings_in(): void
    {
        $query = $this->builder()->from('users')
            ->where('name', "O'Brien")
            ->where('age', 30)
            ->whereNull('deleted_at');

        $this->assertSame(
            "SELECT * FROM `users` WHERE `name` = 'O''Brien' AND `age` = 30 AND `deleted_at` IS NULL",
            $query->toRawSql()
        );
    }

    public function test_to_raw_sql_survives_a_value_with_a_backslash(): void
    {
        $query = $this->builder()->from('files')->where('path', 'C:\\temp\\$1');

        $this->assertSame(
            "SELECT * FROM `files` WHERE `path` = 'C:\\temp\\\$1'",
            $query->toRawSql()
        );
    }
}
