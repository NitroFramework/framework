<?php

namespace Tests\Unit\Database;

use Nitro\Database\Connection;
use Nitro\Database\Connectors\ConnectionFactory;
use Nitro\Database\DB;
use Nitro\Database\Query\Grammar\SqliteGrammar;
use Nitro\Database\Query\QueryBuilder;
use Nitro\Database\RecordModificationState;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Closure;

/**
 * A connection that writes to one server and reads from another.
 *
 * Two SQLite files stand in for the servers, each holding a row saying which
 * one it is, so every read shows where it went.
 */
class ReadWriteConnectionTest extends TestCase
{
    private string $writeFile;

    private string $readFile;

    protected function setUp(): void
    {
        $this->writeFile = $this->database('write');
        $this->readFile = $this->database('read');
    }

    protected function tearDown(): void
    {
        foreach ([$this->writeFile, $this->readFile] as $file) {
            @unlink($file);
        }
    }

    public function test_selects_go_to_the_read_connection_and_writes_to_the_write_one(): void
    {
        $connection = $this->connection();

        $this->assertSame('read', $this->server($connection));

        $connection->insert("insert into servers (name) values ('written')");

        $this->assertSame(1, $this->rowsNamed($this->writeFile, 'written'));
        $this->assertSame(0, $this->rowsNamed($this->readFile, 'written'));
    }

    public function test_each_side_overrides_the_shared_config(): void
    {
        $connection = $this->connection();

        $this->assertSame($this->writeFile, $connection->getConfig()['database']);
        $this->assertSame($this->readFile, $connection->getReadPdoConfig()['database']);
        $this->assertArrayNotHasKey('read', $connection->getConfig());
    }

    public function test_a_side_given_as_a_list_picks_one(): void
    {
        $connection = $this->connection(read: [['database' => $this->readFile], ['database' => $this->readFile]]);

        $this->assertSame('read', $this->server($connection));
    }

    public function test_without_sticky_reads_stay_on_the_read_connection_after_a_write(): void
    {
        $connection = $this->connection();

        $connection->insert("insert into servers (name) values ('x')");

        $this->assertSame('read', $this->server($connection));
    }

    public function test_with_sticky_a_read_after_a_write_goes_to_the_write_connection(): void
    {
        $connection = $this->connection(sticky: true);

        $this->assertSame('read', $this->server($connection));

        $connection->insert("insert into servers (name) values ('x')");

        $this->assertTrue($connection->hasModifiedRecords());
        $this->assertSame('write', $this->server($connection));

        $connection->forgetRecordModificationState();

        $this->assertSame('read', $this->server($connection));
    }

    public function test_an_update_that_changes_nothing_is_not_a_write(): void
    {
        $connection = $this->connection(sticky: true);

        $connection->update("update servers set name = 'y' where name = 'nobody'");

        $this->assertFalse($connection->hasModifiedRecords());
        $this->assertSame('read', $this->server($connection));
    }

    public function test_inside_a_transaction_reads_go_to_the_write_connection(): void
    {
        $connection = $this->connection();

        $connection->getPdo()->beginTransaction();

        try {
            $this->assertSame('write', $this->server($connection));
        } finally {
            $connection->getPdo()->rollBack();
        }

        $this->assertSame('read', $this->server($connection));
    }

    public function test_a_select_can_ask_for_the_write_connection(): void
    {
        $connection = $this->connection();

        $this->assertSame('write', $connection->select('select name from servers limit 1', [], false)[0]->name);
        $this->assertSame('write', $connection->selectFromWriteConnection('select name from servers limit 1')[0]->name);
        $this->assertSame('write', $connection->selectOne('select name from servers limit 1', [], false)->name);
    }

    public function test_reads_can_be_sent_to_the_write_connection_until_told_otherwise(): void
    {
        $connection = $this->connection();

        $connection->useWriteConnectionWhenReading();
        $this->assertSame('write', $this->server($connection));

        $connection->useWriteConnectionWhenReading(false);
        $this->assertSame('read', $this->server($connection));
    }

    public function test_the_query_builder_can_read_from_the_write_connection(): void
    {
        $connection = $this->connection();

        $this->assertSame('read', $this->builder($connection)->value('name'));
        $this->assertSame('write', $this->builder($connection)->useWritePdo()->value('name'));
    }

    /** A lock taken on a replica protects nothing the write server is changing. */
    public function test_a_locking_read_goes_to_the_write_connection(): void
    {
        $connection = $this->connection();

        $this->assertSame('write', $this->builder($connection)->lockForUpdate()->value('name'));
        $this->assertSame('write', $this->builder($connection)->sharedLock()->value('name'));
    }

    public function test_the_read_connection_opens_on_the_first_read(): void
    {
        $connection = $this->connection();

        $readPdo = new ReflectionProperty(Connection::class, 'readPdo');

        $this->assertInstanceOf(Closure::class, $readPdo->getValue($connection));

        $this->server($connection);

        $this->assertInstanceOf(PDO::class, $readPdo->getValue($connection));
    }

    public function test_after_disconnecting_both_sides_reconnect(): void
    {
        $connection = $this->connection();

        $this->server($connection);
        $connection->disconnect();

        $this->assertSame('read', $this->server($connection));
        $this->assertTrue($connection->insert("insert into servers (name) values ('again')"));
    }

    public function test_a_statement_is_cached_once_per_connection(): void
    {
        $connection = $this->connection();

        $connection->select('select name from servers limit 1');
        $connection->select('select name from servers limit 1', [], false);

        $this->assertSame(2, $connection->getStatementCacheSize());
        $this->assertSame('read', $this->server($connection));
        $this->assertSame('write', $connection->selectFromWriteConnection('select name from servers limit 1')[0]->name);
    }

    /** The worker's per-request reset clears what one request wrote. */
    public function test_the_worker_reset_forgets_that_the_connection_wrote(): void
    {
        $connectionProperty = new ReflectionProperty(DB::class, 'connection');
        $previous = $connectionProperty->getValue();

        try {
            $connection = $this->connection(sticky: true);
            $connectionProperty->setValue(null, $connection);

            $connection->insert("insert into servers (name) values ('x')");
            $this->assertSame('write', $this->server($connection));

            (new RecordModificationState())->resetBetweenRequests();

            $this->assertSame('read', $this->server($connection));
        } finally {
            $connectionProperty->setValue(null, $previous);
        }
    }

    // ─── Helpers ──────────────────────────────────────────

    /** @param array<int|string, mixed>|null $read */
    private function connection(bool $sticky = false, ?array $read = null): Connection
    {
        return (new ConnectionFactory())->make([
            'driver' => 'sqlite',
            'read'   => $read ?? ['database' => $this->readFile],
            'write'  => ['database' => $this->writeFile],
            'sticky' => $sticky,
        ], 'split');
    }

    private function server(Connection $connection): string
    {
        return $connection->selectOne("select name from servers where name in ('read', 'write')")->name;
    }

    private function builder(Connection $connection): QueryBuilder
    {
        return (new QueryBuilder($connection, new SqliteGrammar()))
            ->table('servers')
            ->whereIn('name', ['read', 'write']);
    }

    private function rowsNamed(string $file, string $name): int
    {
        $pdo = new PDO('sqlite:' . $file);

        return (int) $pdo->query("select count(*) from servers where name = '{$name}'")->fetchColumn();
    }

    private function database(string $name): string
    {
        $file = tempnam(sys_get_temp_dir(), "nitro-{$name}");

        $pdo = new PDO('sqlite:' . $file);
        $pdo->exec('create table servers (name text)');
        $pdo->exec("insert into servers (name) values ('{$name}')");

        return $file;
    }
}
