<?php

namespace Tests\Unit\Database;

use Nitro\Database\Connection;
use Nitro\Database\Query\Grammar\SqliteGrammar;
use Nitro\Database\Query\QueryBuilder;
use Nitro\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * The execution side of the query builder, against real SQLite.
 */
class QueryExecutionSurfaceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite required');
        }

        $this->connection = new class([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]) extends Connection {
            protected function buildDsn(array $config): string
            {
                return 'sqlite::memory:';
            }

            protected function afterConnect(\PDO $pdo): void
            {
            }
        };


        $this->connection->statement(
            'CREATE TABLE widgets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sku TEXT UNIQUE,
                name TEXT,
                stock INTEGER DEFAULT 0,
                reserved INTEGER DEFAULT 0,
                status TEXT
            )'
        );

        $this->connection->statement('CREATE TABLE widget_archive (sku TEXT, name TEXT)');

        foreach ([
            ['a-1', 'Anvil', 5, 'active'],
            ['b-2', 'Bolt', 0, 'active'],
            ['c-3', 'Cog', 12, 'retired'],
            ['d-4', 'Drum', 7, 'active'],
        ] as [$sku, $name, $stock, $status]) {
            $this->connection->insert(
                'INSERT INTO widgets (sku, name, stock, status) VALUES (?, ?, ?, ?)',
                [$sku, $name, $stock, $status]
            );
        }
    }

    private function table(string $table): QueryBuilder
    {
        return (new QueryBuilder($this->connection, new SqliteGrammar()))->from($table);
    }

    private function widgets(): QueryBuilder
    {
        return $this->table('widgets');
    }

    // ─── Reads ────────────────────────────────────────────

    public function test_sole_returns_the_single_match(): void
    {
        $this->assertSame('Anvil', $this->widgets()->where('sku', 'a-1')->sole()->name);
    }

    public function test_sole_raises_when_several_match(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Multiple records');

        $this->widgets()->where('status', 'active')->sole();
    }

    public function test_sole_raises_when_nothing_matches(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No records');

        $this->widgets()->where('sku', 'nope')->sole();
    }

    public function test_sole_value(): void
    {
        $this->assertSame('Anvil', $this->widgets()->where('sku', 'a-1')->soleValue('name'));
    }

    public function test_find_or(): void
    {
        $this->assertSame('Anvil', $this->widgets()->findOr(1, fn (): string => 'missing')->name);
        $this->assertSame('missing', $this->widgets()->findOr(999, fn (): string => 'missing'));
    }

    public function test_exists_or_and_doesnt_exist_or(): void
    {
        $this->assertTrue($this->widgets()->where('sku', 'a-1')->existsOr(fn (): string => 'no'));
        $this->assertSame('no', $this->widgets()->where('sku', 'zz')->existsOr(fn (): string => 'no'));
        $this->assertTrue($this->widgets()->where('sku', 'zz')->doesntExistOr(fn (): string => 'yes'));
    }

    public function test_implode(): void
    {
        $this->assertSame(
            'Anvil, Bolt, Cog, Drum',
            $this->widgets()->orderBy('id')->implode('name', ', ')
        );
    }

    public function test_raw_value(): void
    {
        $this->assertSame(24, (int) $this->widgets()->rawValue('SUM(stock)'));
    }

    public function test_average(): void
    {
        $this->assertSame(6.0, $this->widgets()->average('stock'));
    }

    public function test_cursor_streams_every_row(): void
    {
        $names = [];

        foreach ($this->widgets()->orderBy('id')->cursor() as $row) {
            $names[] = $row->name;
        }

        $this->assertSame(['Anvil', 'Bolt', 'Cog', 'Drum'], $names);
    }

    public function test_cursor_can_be_abandoned_part_way(): void
    {
        foreach ($this->widgets()->orderBy('id')->cursor() as $row) {
            $this->assertSame('Anvil', $row->name);
            break;
        }

        // The connection is still usable once the generator is discarded.
        $this->assertSame(4, $this->widgets()->count());
    }

    // ─── Filters against real rows ────────────────────────

    public function test_where_like_matches(): void
    {
        $this->assertSame(1, $this->widgets()->whereLike('name', 'A%')->count());
        $this->assertSame(3, $this->widgets()->whereNotLike('name', 'A%')->count());
    }

    public function test_where_any(): void
    {
        $this->assertSame(
            2,
            $this->widgets()->whereAny(['name', 'sku'], 'like', '%o%')->count()
        );
    }

    public function test_where_not(): void
    {
        $this->assertSame(1, $this->widgets()->whereNot('status', 'active')->count());
    }

    public function test_nested_or_group_binds_in_order(): void
    {
        $rows = $this->widgets()
            ->where('status', 'active')
            ->where(function (QueryBuilder $group): void {
                $group->where('stock', '>', 6)->orWhere('sku', 'a-1');
            })
            ->orderBy('id')
            ->pluck('sku');

        $this->assertSame(['a-1', 'd-4'], $rows);
    }

    public function test_union(): void
    {
        $active = $this->widgets()->select('sku')->where('status', 'active');
        $retired = $this->widgets()->select('sku')->where('status', 'retired');

        $rows = $active->union($retired)->get();

        $this->assertCount(4, $rows);
    }

    public function test_join_with_conditions(): void
    {
        $this->connection->insert('INSERT INTO widget_archive (sku, name) VALUES (?, ?)', ['a-1', 'Anvil']);
        $this->connection->insert('INSERT INTO widget_archive (sku, name) VALUES (?, ?)', ['c-3', 'Cog']);

        $rows = $this->widgets()
            ->join('widget_archive', function ($join): void {
                $join->on('widget_archive.sku', '=', 'widgets.sku')
                     ->where('widget_archive.name', '=', 'Anvil');
            })
            ->pluck('widgets.sku');

        $this->assertSame(['a-1'], $rows);
    }

    // ─── JSON, run for real ───────────────────────────────

    private function seedJsonRows(): void
    {
        $this->connection->statement('CREATE TABLE profiles (id INTEGER PRIMARY KEY, options TEXT)');

        foreach ([
            [1, '{"theme":"dark","languages":["en","fr"],"tags":["php","sql"]}'],
            [2, '{"theme":"light","languages":["de"],"tags":["go"]}'],
            [3, '{"languages":[],"tags":[]}'],
        ] as [$id, $options]) {
            $this->connection->insert('INSERT INTO profiles (id, options) VALUES (?, ?)', [$id, $options]);
        }
    }

    public function test_json_path_comparison_runs(): void
    {
        $this->seedJsonRows();

        $this->assertSame(
            [1],
            array_map('intval', $this->table('profiles')->where('options->theme', 'dark')->pluck('id'))
        );
    }

    public function test_where_json_contains_runs(): void
    {
        $this->seedJsonRows();

        $this->assertSame(
            [1],
            array_map('intval', $this->table('profiles')->whereJsonContains('options->languages', 'fr')->pluck('id'))
        );
    }

    public function test_where_json_length_runs(): void
    {
        $this->seedJsonRows();

        $this->assertSame(
            [1],
            array_map('intval', $this->table('profiles')->whereJsonLength('options->languages', '>', 1)->pluck('id'))
        );
    }

    public function test_where_json_contains_key_runs(): void
    {
        $this->seedJsonRows();

        $this->assertSame(
            [1, 2],
            array_map('intval', $this->table('profiles')->whereJsonContainsKey('options->theme')->pluck('id'))
        );

        $this->assertSame(
            [3],
            array_map('intval', $this->table('profiles')->whereJsonDoesntContainKey('options->theme')->pluck('id'))
        );
    }

    public function test_where_json_overlaps_runs(): void
    {
        $this->seedJsonRows();

        $this->assertSame(
            [2],
            array_map('intval', $this->table('profiles')->whereJsonOverlaps('options->tags', ['go', 'rust'])->pluck('id'))
        );
    }

    // ─── Writes ───────────────────────────────────────────

    public function test_insert_or_ignore_skips_a_duplicate(): void
    {
        $inserted = $this->widgets()->insertOrIgnore([
            ['sku' => 'a-1', 'name' => 'Duplicate Anvil'],
            ['sku' => 'e-5', 'name' => 'Ember'],
        ]);

        $this->assertSame(1, $inserted);
        $this->assertSame('Anvil', $this->widgets()->where('sku', 'a-1')->value('name'));
        $this->assertSame(5, $this->widgets()->count());
    }

    public function test_insert_using(): void
    {
        $inserted = $this->table('widget_archive')->insertUsing(
            ['sku', 'name'],
            function (QueryBuilder $query): void {
                $query->from('widgets')->select('sku', 'name')->where('status', 'active');
            }
        );

        $this->assertSame(3, $inserted);
        $this->assertSame(3, $this->table('widget_archive')->count());
    }

    public function test_update_or_insert_inserts_when_absent(): void
    {
        $this->assertTrue($this->widgets()->updateOrInsert(['sku' => 'z-9'], ['name' => 'Zed', 'stock' => 3]));
        $this->assertSame('Zed', $this->widgets()->where('sku', 'z-9')->value('name'));
    }

    public function test_update_or_insert_updates_when_present(): void
    {
        $this->widgets()->updateOrInsert(['sku' => 'a-1'], ['name' => 'Anvil II']);

        $this->assertSame('Anvil II', $this->widgets()->where('sku', 'a-1')->value('name'));
        $this->assertSame(4, $this->widgets()->count());
    }

    public function test_increment_each(): void
    {
        $this->widgets()->where('sku', 'a-1')->incrementEach(['stock' => 2, 'reserved' => 1]);

        $row = $this->widgets()->where('sku', 'a-1')->first();

        $this->assertSame(7, (int) $row->stock);
        $this->assertSame(1, (int) $row->reserved);
    }

    public function test_decrement_each(): void
    {
        $this->widgets()->where('sku', 'c-3')->decrementEach(['stock' => 2]);

        $this->assertSame(10, (int) $this->widgets()->where('sku', 'c-3')->value('stock'));
    }

    // ─── Walking large sets ───────────────────────────────

    public function test_chunk_by_id_visits_every_row_once(): void
    {
        $seen = [];

        $this->widgets()->chunkById(2, function (Collection $rows) use (&$seen): void {
            foreach ($rows as $row) {
                $seen[] = $row->sku;
            }
        });

        $this->assertSame(['a-1', 'b-2', 'c-3', 'd-4'], $seen);
    }

    public function test_chunk_by_id_stops_when_the_callback_returns_false(): void
    {
        $pages = 0;

        $result = $this->widgets()->chunkById(2, function () use (&$pages): bool {
            $pages++;

            return false;
        });

        $this->assertFalse($result);
        $this->assertSame(1, $pages);
    }

    public function test_each(): void
    {
        $seen = [];

        $this->widgets()->orderBy('id')->each(function (object $row) use (&$seen): void {
            $seen[] = $row->sku;
        }, 2);

        $this->assertSame(['a-1', 'b-2', 'c-3', 'd-4'], $seen);
    }

    // ─── Pagination ───────────────────────────────────────

    public function test_simple_paginate_knows_there_is_a_next_page(): void
    {
        $page = $this->widgets()->orderBy('id')->simplePaginate(2, 1);

        $this->assertCount(2, $page->items());
        $this->assertTrue($page->hasMorePages());
    }

    public function test_simple_paginate_on_the_last_page(): void
    {
        $page = $this->widgets()->orderBy('id')->simplePaginate(2, 2);

        $this->assertCount(2, $page->items());
        $this->assertFalse($page->hasMorePages());
    }

    public function test_get_count_for_pagination_ignores_the_window(): void
    {
        $this->assertSame(
            4,
            $this->widgets()->orderBy('name')->limit(2)->offset(1)->getCountForPagination()
        );
    }

    public function test_for_page_after_id_walks_forward(): void
    {
        $rows = $this->widgets()->forPageAfterId(2, 1)->pluck('sku');

        $this->assertSame(['b-2', 'c-3'], $rows);
    }
}
