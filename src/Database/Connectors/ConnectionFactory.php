<?php

namespace Nitro\Database\Connectors;

use Closure;
use InvalidArgumentException;
use Nitro\Container\Container;
use Nitro\Database\Connection;
use PDO;
use PDOException;

/**
 * Builds a database connection from its config.
 *
 *     $connection = (new ConnectionFactory())->make(config('database.connections.mysql'), 'mysql');
 *
 * Picks the connector for the driver and hands the connection a closure that
 * opens the PDO, so nothing connects until the first query. A 'host' given as
 * a list is tried in random order until one answers.
 *
 * A driver the framework does not ship is added by binding a connector as
 * "db.connector.{driver}" in the container.
 */
class ConnectionFactory
{
    /**
     * Build a connection, not yet connected.
     *
     * @param array<string, mixed> $config
     */
    public function make(array $config, ?string $name = null): Connection
    {
        $config = $this->parseConfig($config, $name);

        if (isset($config['read'])) {
            return $this->createReadWriteConnection($config);
        }

        return $this->createSingleConnection($config);
    }

    /** @param array<string, mixed> $config */
    protected function createSingleConnection(array $config): Connection
    {
        return $this->createConnection($config, $this->createPdoResolver($config));
    }

    /**
     * A connection that writes to one server and reads from another.
     *
     *     'mysql' => [
     *         'read'   => ['host' => ['10.0.0.2', '10.0.0.3']],
     *         'write'  => ['host' => '10.0.0.1'],
     *         'sticky' => true,
     *         'driver' => 'mysql', 'database' => 'shop', ...
     *     ]
     *
     * Each side's keys override the shared ones. Given a list of configs
     * rather than one, a side picks one of them at random.
     *
     * @param array<string, mixed> $config
     */
    protected function createReadWriteConnection(array $config): Connection
    {
        $connection = $this->createSingleConnection($this->getWriteConfig($config));

        return $connection
            ->setReadPdo($this->createPdoResolver($readConfig = $this->getReadConfig($config)))
            ->setReadPdoConfig($readConfig);
    }

    /**
     * @param  array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function getReadConfig(array $config): array
    {
        return $this->mergeReadWriteConfig($config, $this->getReadWriteConfig($config, 'read'));
    }

    /**
     * @param  array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function getWriteConfig(array $config): array
    {
        return $this->mergeReadWriteConfig($config, $this->getReadWriteConfig($config, 'write'));
    }

    /**
     * One side's own settings, picking at random when it lists several.
     *
     * @param  array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function getReadWriteConfig(array $config, string $type): array
    {
        $side = $config[$type] ?? [];

        return isset($side[0]) ? $side[array_rand($side)] : $side;
    }

    /**
     * @param  array<string, mixed> $config
     * @param  array<string, mixed> $merge
     * @return array<string, mixed>
     */
    protected function mergeReadWriteConfig(array $config, array $merge): array
    {
        $merged = array_merge($config, $merge);

        unset($merged['read'], $merged['write']);

        return $merged;
    }

    /**
     * The connector for a config's driver.
     *
     * @param array<string, mixed> $config
     * @throws InvalidArgumentException When no driver is given, or it is not supported.
     */
    public function createConnector(array $config): ConnectorInterface
    {
        if (! isset($config['driver'])) {
            throw new InvalidArgumentException('A driver must be specified.');
        }

        $key = "db.connector.{$config['driver']}";

        if (Container::hasInstance() && Container::getInstance()->has($key)) {
            return Container::getInstance()->resolve($key);
        }

        return match ($config['driver']) {
            'mysql', 'mariadb' => new MySqlConnector(),
            'pgsql', 'postgres', 'postgresql' => new PostgresConnector(),
            'sqlite' => new SQLiteConnector(),
            default => throw new InvalidArgumentException("Unsupported driver [{$config['driver']}]."),
        };
    }

    /**
     * Defaults every connection config carries: a table prefix and its name.
     *
     * @param  array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function parseConfig(array $config, ?string $name): array
    {
        return $config + ['prefix' => '', 'name' => $name];
    }

    /**
     * The closure that opens the PDO when the connection first needs one.
     *
     * @param array<string, mixed> $config
     * @return Closure(): PDO
     */
    protected function createPdoResolver(array $config): Closure
    {
        return array_key_exists('host', $config)
            ? $this->createPdoResolverWithHosts($config)
            : fn (): PDO => $this->createConnector($config)->connect($config);
    }

    /**
     * Try each host in random order, keeping the last failure to throw if
     * none answers.
     *
     * @param array<string, mixed> $config
     * @return Closure(): PDO
     */
    protected function createPdoResolverWithHosts(array $config): Closure
    {
        return function () use ($config): PDO {
            $exception = null;

            $hosts = $this->parseHosts($config);

            shuffle($hosts);

            foreach ($hosts as $host) {
                $config['host'] = $host;

                try {
                    return $this->createConnector($config)->connect($config);
                } catch (PDOException $e) {
                    $exception = $e;
                }
            }

            throw $exception;
        };
    }

    /**
     * @param  array<string, mixed> $config
     * @return array<int, string>
     */
    protected function parseHosts(array $config): array
    {
        $hosts = (array) $config['host'];

        if ($hosts === []) {
            throw new InvalidArgumentException('Database hosts array is empty.');
        }

        return array_values($hosts);
    }

    /**
     * @param array<string, mixed> $config
     * @param Closure(): PDO       $pdo
     */
    protected function createConnection(array $config, Closure $pdo): Connection
    {
        return new Connection($config, $pdo);
    }
}
