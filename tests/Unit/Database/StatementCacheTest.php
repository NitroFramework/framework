<?php

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Nitro\Database\Connection;

/**
 * Statement-cache behavior verified against a real SQLite memory DB.
 * Skips if no PDO SQLite driver is available.
 */
class StatementCacheTest extends TestCase
{
    private function connection(): Connection
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite required');
        }
        // Subclass overrides DSN/charset so the SQLite test runs without
        // the MySQL-specific SET NAMES.
        return new class([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]) extends Connection {
            protected function buildDsn(array $c): string { return 'sqlite::memory:'; }
            protected function afterConnect(\PDO $pdo): void {}
        };
    }

    public function test_repeated_query_reuses_cached_statement(): void
    {
        $c = $this->connection();
        $c->statement('CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER)');
        $c->insert('INSERT INTO t (n) VALUES (?)', [1]);
        $c->insert('INSERT INTO t (n) VALUES (?)', [2]);
        $c->insert('INSERT INTO t (n) VALUES (?)', [3]);

        $this->assertGreaterThanOrEqual(2, $c->getStatementCacheSize());

        // Reads with the same SQL should not push more entries (LRU
        // refresh, not new insert).
        $sizeBefore = $c->getStatementCacheSize();
        $c->select('SELECT * FROM t WHERE n = ?', [1]);
        $c->select('SELECT * FROM t WHERE n = ?', [2]);
        $c->select('SELECT * FROM t WHERE n = ?', [3]);
        $sizeAfter = $c->getStatementCacheSize();

        // We added 1 unique SELECT — it shouldn't multiply across calls.
        $this->assertSame($sizeBefore + 1, $sizeAfter);
    }

    public function test_cache_lru_evicts_oldest(): void
    {
        $c = $this->connection();
        $c->setStatementCacheLimit(3);
        $c->statement('CREATE TABLE t (id INTEGER PRIMARY KEY)');

        $c->select('SELECT 1');
        $c->select('SELECT 2');
        $c->select('SELECT 3');
        $this->assertSame(3, $c->getStatementCacheSize());

        $c->select('SELECT 4'); // evicts SELECT 1
        $this->assertSame(3, $c->getStatementCacheSize());

        // Touch SELECT 2 — refreshes its LRU position.
        $c->select('SELECT 2');
        $c->select('SELECT 5'); // evicts SELECT 3 (oldest after the touch)
        $this->assertSame(3, $c->getStatementCacheSize());
    }

    public function test_flush_drops_all_cached_statements(): void
    {
        $c = $this->connection();
        $c->statement('CREATE TABLE t (id INTEGER)');
        $c->select('SELECT 1');
        $c->select('SELECT 2');
        $this->assertGreaterThan(0, $c->getStatementCacheSize());

        $c->flushStatementCache();
        $this->assertSame(0, $c->getStatementCacheSize());
    }

    public function test_pdo_error_evicts_failed_statement(): void
    {
        $c = $this->connection();
        $c->flushStatementCache();
        $c->statement('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $beforeError = $c->getStatementCacheSize();

        try {
            $c->select('SELECT * FROM nonexistent_table');
            $this->fail('Expected PDOException');
        } catch (\PDOException) {
            // Expected — the FAILED statement should NOT linger in the
            // cache, but other prior-cached statements remain.
        }

        $this->assertSame($beforeError, $c->getStatementCacheSize(),
            'Only the failed statement should be evicted, not the existing cache.');
    }

    public function test_bindings_string_in_error_message(): void
    {
        $c = $this->connection();
        $c->statement('CREATE TABLE t (id INTEGER PRIMARY KEY)');

        try {
            $c->select('SELECT * FROM t WHERE bad_col = ?', ['secret']);
            $this->fail('Expected PDOException');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('Bindings: ["secret"]', $e->getMessage());
        }
    }
}
