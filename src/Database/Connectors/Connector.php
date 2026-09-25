<?php

namespace Nitro\Database\Connectors;

use InvalidArgumentException;
use PDO;

/**
 * What every connector shares: the PDO options, and opening the connection.
 *
 * A driver's connector supplies the DSN and the setup after connecting;
 * connect() puts the two either side of opening the PDO.
 */
abstract class Connector implements ConnectorInterface
{
    /**
     * The options every connection opens with.
     *
     * Objects rather than arrays as the default fetch mode, because the query
     * layer reads rows as objects.
     *
     * @var array<int, mixed>
     */
    protected array $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    public function connect(array $config): PDO
    {
        $connection = $this->createConnection($this->getDsn($config), $config, $this->getOptions($config));

        $this->configureConnection($connection, $config);

        return $connection;
    }

    /**
     * Open the PDO connection.
     *
     * @param array<string, mixed> $config
     * @param array<int, mixed>    $options
     */
    public function createConnection(string $dsn, array $config, array $options): PDO
    {
        return new PDO(
            $dsn,
            $config['username'] ?? 'root',
            $config['password'] ?? '',
            $options,
        );
    }

    /**
     * The defaults, with the connection's own 'options' laid over them.
     *
     * @param  array<string, mixed> $config
     * @return array<int, mixed>
     */
    public function getOptions(array $config): array
    {
        $options = $config['options'] ?? [];

        return array_diff_key($this->options, $options) + $options;
    }

    /** @return array<int, mixed> */
    public function getDefaultOptions(): array
    {
        return $this->options;
    }

    /** @param array<int, mixed> $options */
    public function setDefaultOptions(array $options): void
    {
        $this->options = $options;
    }

    /**
     * Check a charset and collation before they are written into SQL.
     *
     * They reach SET NAMES as text rather than bound parameters, so only
     * identifier-safe characters are allowed.
     */
    protected function assertSafeCharsetAndCollation(string $charset, string $collation): void
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $charset)
            || ! preg_match('/^[A-Za-z0-9_]+$/', $collation)) {
            throw new InvalidArgumentException('Invalid charset or collation in database config.');
        }
    }
}
