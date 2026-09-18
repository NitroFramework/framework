<?php

namespace Tests\Unit\Session;

use Nitro\Database\Connection;
use Nitro\Database\DB;
use Nitro\Database\Query\Grammar\SqliteGrammar;
use Nitro\Session\Handlers\DatabaseSessionHandler;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The SQL session handler, against an in-memory SQLite database.
 */
class DatabaseSessionHandlerTest extends TestCase
{
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

        $connection->statement(
            'CREATE TABLE sessions (
                id TEXT PRIMARY KEY,
                payload TEXT,
                last_activity INTEGER
            )'
        );
    }

    private function handler(int $minutes = 120): DatabaseSessionHandler
    {
        return new DatabaseSessionHandler('sessions', $minutes);
    }

    public function test_a_written_payload_reads_back(): void
    {
        $handler = $this->handler();

        $this->assertTrue($handler->write('abc', 'payload'));
        $this->assertSame('payload', $handler->read('abc'));
    }

    public function test_a_missing_session_reads_as_empty(): void
    {
        $this->assertSame('', $this->handler()->read('nope'));
    }

    /** A serialized session is binary; the column is text. */
    public function test_a_binary_payload_survives_the_round_trip(): void
    {
        $payload = serialize(['bytes' => "\x00\x01\xff", 'utf' => 'café']);

        $handler = $this->handler();
        $handler->write('abc', $payload);

        $this->assertSame($payload, $handler->read('abc'));
    }

    public function test_writing_twice_updates_rather_than_duplicates(): void
    {
        $handler = $this->handler();

        $handler->write('abc', 'first');
        $handler->write('abc', 'second');

        $this->assertSame('second', $handler->read('abc'));
        $this->assertSame(1, DB::table('sessions')->count());
    }

    public function test_a_session_idle_past_its_lifetime_reads_as_empty(): void
    {
        $this->handler()->write('abc', 'payload');

        DB::table('sessions')->where('id', 'abc')->update(['last_activity' => time() - 7200]);

        $this->assertSame('', $this->handler(60)->read('abc'));
    }

    public function test_destroy_removes_the_row(): void
    {
        $handler = $this->handler();
        $handler->write('abc', 'payload');

        $this->assertTrue($handler->destroy('abc'));
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_garbage_collection_removes_only_idle_rows(): void
    {
        $handler = $this->handler();

        $handler->write('fresh', 'payload');
        $handler->write('stale', 'payload');

        DB::table('sessions')->where('id', 'stale')->update(['last_activity' => time() - 7200]);

        $this->assertSame(1, $handler->gc(3600));
        $this->assertSame(['fresh'], DB::table('sessions')->pluck('id'));
    }

    /** The sweep is bounded so its cost does not grow with the backlog. */
    public function test_garbage_collection_honours_its_limit(): void
    {
        $handler = $this->handler();

        foreach (['a', 'b', 'c'] as $id) {
            $handler->write($id, 'payload');
        }

        DB::table('sessions')->update(['last_activity' => time() - 7200]);

        $this->assertSame(2, $handler->gc(3600, 2));
        $this->assertSame(1, DB::table('sessions')->count());
    }

    public function test_an_unbounded_sweep_removes_everything_idle(): void
    {
        $handler = $this->handler();

        foreach (['a', 'b', 'c'] as $id) {
            $handler->write($id, 'payload');
        }

        DB::table('sessions')->update(['last_activity' => time() - 7200]);

        $this->assertSame(3, $handler->gc(3600, 0));
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_open_and_close_succeed(): void
    {
        $handler = $this->handler();

        $this->assertTrue($handler->open('', 'nitro_session'));
        $this->assertTrue($handler->close());
    }
}
