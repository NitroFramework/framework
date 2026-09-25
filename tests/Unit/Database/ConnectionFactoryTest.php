<?php

namespace Tests\Unit\Database;

use InvalidArgumentException;
use Nitro\Container\Container;
use Nitro\Database\Connection;
use Nitro\Database\Connectors\ConnectionFactory;
use Nitro\Database\Connectors\Connector;
use Nitro\Database\Connectors\MySqlConnector;
use Nitro\Database\Connectors\PostgresConnector;
use Nitro\Database\Connectors\SQLiteConnector;
use Nitro\Database\SQLiteDatabaseDoesNotExistException;
use PDO;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Building a connection from its config, and the connector for each driver.
 */
class ConnectionFactoryTest extends TestCase
{
    /** The global container as the test found it, put back afterwards. */
    private ?Container $previous = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previous = Container::hasInstance() ? Container::getInstance() : null;
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous ?? new Container());

        parent::tearDown();
    }

    public function test_it_builds_a_working_connection(): void
    {
        $connection = (new ConnectionFactory())->make(['driver' => 'sqlite', 'database' => ':memory:'], 'testing');

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertSame(1, (int) $connection->selectOne('select 1 as one')->one);
    }

    public function test_the_config_carries_its_name_and_a_prefix(): void
    {
        $config = (new ConnectionFactory())->make(['driver' => 'sqlite', 'database' => ':memory:'], 'reporting')->getConfig();

        $this->assertSame('reporting', $config['name']);
        $this->assertSame('', $config['prefix']);
    }

    public function test_nothing_connects_until_the_first_query(): void
    {
        $connector = new CountingConnector();

        Container::setInstance($container = new Container());
        $container->instance('db.connector.counting', $connector);

        $connection = (new ConnectionFactory())->make(['driver' => 'counting', 'database' => ':memory:']);

        $this->assertSame(0, $connector->connects);

        $connection->select('select 1');
        $connection->select('select 1');

        $this->assertSame(1, $connector->connects);
    }

    public function test_a_connector_bound_in_the_container_is_used_for_its_driver(): void
    {
        Container::setInstance($container = new Container());
        $container->instance('db.connector.counting', $connector = new CountingConnector());

        $this->assertSame($connector, (new ConnectionFactory())->createConnector(['driver' => 'counting']));
    }

    public function test_each_shipped_driver_has_a_connector(): void
    {
        $factory = new ConnectionFactory();

        $this->assertInstanceOf(MySqlConnector::class, $factory->createConnector(['driver' => 'mysql']));
        $this->assertInstanceOf(MySqlConnector::class, $factory->createConnector(['driver' => 'mariadb']));
        $this->assertInstanceOf(PostgresConnector::class, $factory->createConnector(['driver' => 'pgsql']));
        $this->assertInstanceOf(SQLiteConnector::class, $factory->createConnector(['driver' => 'sqlite']));
    }

    public function test_a_config_without_a_driver_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A driver must be specified.');

        (new ConnectionFactory())->createConnector([]);
    }

    public function test_an_unsupported_driver_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported driver [oracle].');

        (new ConnectionFactory())->createConnector(['driver' => 'oracle']);
    }

    public function test_an_empty_host_list_is_refused(): void
    {
        $connection = (new ConnectionFactory())->make(['driver' => 'mysql', 'host' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database hosts array is empty.');

        $connection->getPdo();
    }

    // ─── DSNs ─────────────────────────────────────────────

    public function test_mysql_dsn(): void
    {
        $this->assertSame(
            'mysql:host=db;port=3307;dbname=shop;charset=utf8mb4',
            (new MySqlConnector())->getDsn(['host' => 'db', 'port' => 3307, 'database' => 'shop']),
        );
    }

    /** The defect: 'mariadb' reached PDO as a driver it does not have. */
    public function test_mariadb_connects_with_the_mysql_driver(): void
    {
        $this->assertStringStartsWith('mysql:', (new MySqlConnector())->getDsn(['driver' => 'mariadb', 'database' => 'shop']));
    }

    public function test_mysql_prefers_a_socket(): void
    {
        $this->assertSame(
            'mysql:unix_socket=/tmp/mysql.sock;dbname=shop;charset=utf8mb4',
            (new MySqlConnector())->getDsn(['unix_socket' => '/tmp/mysql.sock', 'host' => 'db', 'database' => 'shop']),
        );
    }

    public function test_postgres_dsn_carries_sslmode(): void
    {
        $this->assertSame(
            'pgsql:host=db;port=5432;dbname=shop;sslmode=require',
            (new PostgresConnector())->getDsn(['host' => 'db', 'database' => 'shop', 'sslmode' => 'require']),
        );
    }

    public function test_sqlite_dsn(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'nitro-sqlite');

        try {
            $this->assertSame('sqlite::memory:', (new SQLiteConnector())->getDsn(['database' => ':memory:']));
            $this->assertSame('sqlite:' . realpath($file), (new SQLiteConnector())->getDsn(['database' => $file]));
        } finally {
            unlink($file);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function inMemoryDatabases(): array
    {
        return [
            'anonymous'       => [':memory:'],
            'named, query'    => ['file:shared?mode=memory&cache=shared'],
            'named, and'      => ['db?cache=shared&mode=memory'],
            'any file uri'    => ['file:whatever.db'],
        ];
    }

    #[DataProvider('inMemoryDatabases')]
    public function test_an_in_memory_or_uri_database_passes_as_given(string $database): void
    {
        $this->assertSame('sqlite:' . $database, (new SQLiteConnector())->getDsn(['database' => $database]));
    }

    /** PDO would create an empty file here and connect to it. */
    public function test_a_missing_sqlite_file_is_refused_rather_than_created(): void
    {
        $missing = sys_get_temp_dir() . '/nitro-missing-' . uniqid() . '.sqlite';

        try {
            (new ConnectionFactory())->make(['driver' => 'sqlite', 'database' => $missing])->getPdo();

            $this->fail('A missing database file should not connect.');
        } catch (SQLiteDatabaseDoesNotExistException $exception) {
            $this->assertSame($missing, $exception->path);
            $this->assertFileDoesNotExist($missing);
        }
    }

    // ─── Options and setup ────────────────────────────────

    public function test_the_config_options_are_laid_over_the_defaults(): void
    {
        $options = (new SQLiteConnector())->getOptions(['options' => [PDO::ATTR_TIMEOUT => 9]]);

        $this->assertSame(9, $options[PDO::ATTR_TIMEOUT]);
        $this->assertSame(PDO::FETCH_OBJ, $options[PDO::ATTR_DEFAULT_FETCH_MODE]);
    }

    public function test_sqlite_turns_foreign_keys_on_unless_told_not_to(): void
    {
        $on = (new SQLiteConnector())->connect(['database' => ':memory:']);
        $off = (new SQLiteConnector())->connect(['database' => ':memory:', 'foreign_key_constraints' => false]);

        $this->assertSame(1, (int) $on->query('PRAGMA foreign_keys')->fetchColumn());
        $this->assertSame(0, (int) $off->query('PRAGMA foreign_keys')->fetchColumn());
    }

    public function test_sqlite_applies_the_pragmas_it_is_given(): void
    {
        $pdo = (new SQLiteConnector())->connect([
            'database' => ':memory:',
            'busy_timeout' => 4321,
            'pragmas' => ['cache_size' => -2000],
        ]);

        $this->assertSame(4321, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
        $this->assertSame(-2000, (int) $pdo->query('PRAGMA cache_size')->fetchColumn());
    }

    /** A subclass overriding the DSN still connects through it. */
    public function test_a_connection_subclass_can_still_supply_its_own_dsn(): void
    {
        $connection = new class (['driver' => 'mysql']) extends Connection {
            protected function buildDsn(array $config): string
            {
                return 'sqlite::memory:';
            }

            protected function afterConnect(PDO $pdo): void
            {
            }
        };

        $this->assertSame(1, (int) $connection->selectOne('select 1 as one')->one);
    }
}

class CountingConnector extends Connector
{
    public int $connects = 0;

    public function connect(array $config): PDO
    {
        $this->connects++;

        return parent::connect($config);
    }

    public function getDsn(array $config): string
    {
        return 'sqlite::memory:';
    }

    public function configureConnection(PDO $connection, array $config): void
    {
    }
}
