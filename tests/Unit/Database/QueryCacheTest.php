<?php

namespace Tests\Unit\Database;

use Nitro\Cache\Drivers\ArrayStore;
use Nitro\Cache\Repository;
use Nitro\Container\Container;
use Nitro\Database\Connection;
use Nitro\Database\DB;
use PHPUnit\Framework\TestCase;

/**
 * ->cache($ttl) read-through query caching with per-table version-stamp
 * invalidation, on in-memory SQLite with an array cache store.
 */
class QueryCacheTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite required');
        }

        $this->conn = new class([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]) extends Connection {
            protected function buildDsn(array $c): string { return 'sqlite::memory:'; }
            protected function afterConnect(\PDO $pdo): void {}
        };

        $r = new \ReflectionClass(DB::class);
        $p = $r->getProperty('connection');
        $p->setAccessible(true);
        $p->setValue(null, $this->conn);
        $g = $r->getProperty('grammar');
        $g->setAccessible(true);
        $g->setValue(null, new \Nitro\Database\Query\Grammar\MySqlGrammar());

        $this->conn->statement('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $this->conn->statement("INSERT INTO widgets (name) VALUES ('A'), ('B')");

        // A real (array) cache store so ->cache() and version bumps work.
        Container::getInstance()->instance('cache.store', new Repository(new ArrayStore()));
    }

    protected function tearDown(): void
    {
        DB::disconnect();
    }

    public function test_result_is_cached_until_a_write_invalidates_it(): void
    {
        // First cached read: 2 rows.
        $this->assertSame(2, DB::table('widgets')->cache(60)->count());

        // A raw insert the builder can't see — cache is NOT invalidated, so the
        // next cached read is intentionally stale (proves it hit the cache).
        $this->conn->statement("INSERT INTO widgets (name) VALUES ('C')");
        $this->assertSame(2, DB::table('widgets')->cache(60)->count());

        // A write THROUGH the builder bumps the table version → invalidation.
        DB::table('widgets')->insert(['name' => 'D']);

        // Fresh read now counts everything: A, B, C, D.
        $this->assertSame(4, DB::table('widgets')->cache(60)->count());
    }

    public function test_uncached_queries_always_see_current_data(): void
    {
        $this->assertSame(2, DB::table('widgets')->count());
        $this->conn->statement("INSERT INTO widgets (name) VALUES ('C')");
        $this->assertSame(3, DB::table('widgets')->count()); // no ->cache() → live
    }

    public function test_get_results_are_cached(): void
    {
        $first = DB::table('widgets')->orderBy('id')->cache(60)->get();
        $this->assertCount(2, $first);

        // Raw insert (no bump) → cached get() stays at 2 rows.
        $this->conn->statement("INSERT INTO widgets (name) VALUES ('C')");
        $this->assertCount(2, DB::table('widgets')->orderBy('id')->cache(60)->get());
    }
}
