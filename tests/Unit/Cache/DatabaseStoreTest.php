<?php

namespace Tests\Unit\Cache;

use Nitro\Cache\Drivers\DatabaseStore;
use Nitro\Database\Connection;
use Nitro\Database\DB;
use Nitro\Database\Query\Grammar\SqliteGrammar;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The SQL-backed cache store.
 *
 * Expiry is enforced on read rather than by a sweep, so an entry is never
 * served past its time even if garbage collection has not run.
 */
class DatabaseStoreTest extends TestCase
{
    private DatabaseStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite required');
        }

        $connection = new class(['driver' => 'sqlite', 'database' => ':memory:']) extends Connection {
            protected function buildDsn(array $config): string
            {
                return 'sqlite::memory:';
            }

            protected function afterConnect(PDO $pdo): void
            {
            }
        };

        $reflection = new ReflectionClass(DB::class);

        $property = $reflection->getProperty('connection');
        $property->setAccessible(true);
        $property->setValue(null, $connection);

        $grammar = $reflection->getProperty('grammar');
        $grammar->setAccessible(true);
        $grammar->setValue(null, new SqliteGrammar());

        // "key" is reserved in some engines, so the column is quoted here just
        // as the query grammar quotes it.
        $connection->statement(
            'CREATE TABLE cache (
                "key" TEXT PRIMARY KEY,
                value TEXT,
                expiration INTEGER
            )'
        );

        $this->store = new DatabaseStore('cache', 'test:');
    }

    public function test_a_value_round_trips(): void
    {
        $this->store->put('name', 'Ada', 60);

        $this->assertSame('Ada', $this->store->get('name'));
    }

    public function test_an_absent_key_is_null(): void
    {
        $this->assertNull($this->store->get('nope'));
    }

    public function test_arrays_and_objects_survive(): void
    {
        $this->store->put('list', ['a' => 1, 'b' => [2, 3]], 60);

        $this->assertSame(['a' => 1, 'b' => [2, 3]], $this->store->get('list'));
    }

    /** Expiry is enforced when the entry is read, not by a sweep. */
    public function test_an_expired_entry_is_not_served(): void
    {
        $this->store->put('brief', 'gone', 60);

        DB::table('cache')->where('key', 'test:brief')->update(['expiration' => time() - 1]);

        $this->assertNull($this->store->get('brief'));
    }

    public function test_forever_never_expires(): void
    {
        $this->store->forever('permanent', 'here');

        $row = (array) DB::table('cache')->where('key', 'test:permanent')->first();

        $this->assertSame(0, (int) $row['expiration']);
        $this->assertSame('here', $this->store->get('permanent'));
    }

    public function test_putting_over_an_existing_key_replaces_it(): void
    {
        $this->store->put('name', 'Ada', 60);
        $this->store->put('name', 'Grace', 60);

        $this->assertSame('Grace', $this->store->get('name'));
    }

    public function test_forget_removes_the_entry(): void
    {
        $this->store->put('name', 'Ada', 60);
        $this->store->forget('name');

        $this->assertNull($this->store->get('name'));
    }

    public function test_flush_empties_the_store(): void
    {
        $this->store->put('a', 1, 60);
        $this->store->put('b', 2, 60);
        $this->store->flush();

        $this->assertNull($this->store->get('a'));
        $this->assertNull($this->store->get('b'));
    }

    // ─── add(), which locks are built on ──────────────────────

    public function test_add_stores_when_the_key_is_absent(): void
    {
        $this->assertTrue($this->store->add('lock', 'mine', 60));
        $this->assertSame('mine', $this->store->get('lock'));
    }

    /** Two callers must not both believe they took the lock. */
    public function test_add_refuses_when_the_key_is_held(): void
    {
        $this->assertTrue($this->store->add('lock', 'first', 60));
        $this->assertFalse($this->store->add('lock', 'second', 60));

        $this->assertSame('first', $this->store->get('lock'));
    }

    /** An expired lock is claimable, or a crashed holder would hold it forever. */
    public function test_add_claims_a_key_that_has_expired(): void
    {
        $this->store->add('lock', 'first', 60);

        DB::table('cache')->where('key', 'test:lock')->update(['expiration' => time() - 1]);

        $this->assertTrue($this->store->add('lock', 'second', 60));
        $this->assertSame('second', $this->store->get('lock'));
    }

    // ─── Counters ─────────────────────────────────────────────

    public function test_increment_and_decrement(): void
    {
        $this->store->put('hits', 5, 60);

        $this->assertSame(6, $this->store->increment('hits'));
        $this->assertSame(9, $this->store->increment('hits', 3));
        $this->assertSame(8, $this->store->decrement('hits'));
    }

    public function test_incrementing_an_absent_key_starts_at_zero(): void
    {
        $this->assertSame(1, $this->store->increment('fresh'));
    }

    public function test_incrementing_a_non_numeric_value_fails(): void
    {
        $this->store->put('word', 'Ada', 60);

        $this->assertFalse($this->store->increment('word'));
    }

    // ─── Housekeeping ─────────────────────────────────────────

    public function test_garbage_collection_removes_only_expired_rows(): void
    {
        $this->store->put('live', 'a', 60);
        $this->store->put('dead', 'b', 60);
        $this->store->forever('permanent', 'c');

        DB::table('cache')->where('key', 'test:dead')->update(['expiration' => time() - 1]);

        $this->assertSame(1, $this->store->collectGarbage());
        $this->assertSame('a', $this->store->get('live'));
        $this->assertSame('c', $this->store->get('permanent'));
    }

    public function test_keys_carry_the_prefix(): void
    {
        $this->store->put('name', 'Ada', 60);

        $this->assertNotNull(DB::table('cache')->where('key', 'test:name')->first());
        $this->assertSame('test:', $this->store->getPrefix());
    }

    public function test_many_reads_several_keys(): void
    {
        $this->store->putMany(['a' => 1, 'b' => 2], 60);

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => null], $this->store->many(['a', 'b', 'c']));
    }
}
