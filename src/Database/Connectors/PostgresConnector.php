<?php

namespace Nitro\Database\Connectors;

use InvalidArgumentException;
use PDO;

/**
 * Connects to PostgreSQL.
 */
class PostgresConnector extends Connector
{
    /**
     * Neither charset nor collation goes in the DSN: the client encoding is
     * set once the connection is open. sslmode does, because libpq reads it
     * while connecting.
     */
    public function getDsn(array $config): string
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 5432;
        $database = $config['database'] ?? '';

        $dsn = "pgsql:host={$host};port={$port};dbname={$database}";

        if (isset($config['sslmode'])) {
            $dsn .= ";sslmode={$config['sslmode']}";
        }

        return $dsn;
    }

    /**
     * No COLLATE on the session: a collation belongs to a column or a
     * comparison, not a connection.
     */
    public function configureConnection(PDO $connection, array $config): void
    {
        $charset = $config['charset'] ?? 'utf8';

        $this->assertSafeCharsetAndCollation($charset, 'utf8');

        $connection->exec("SET NAMES '{$charset}'");

        $this->configureSearchPath($connection, $config);
        $this->configureTimezone($connection, $config);
    }

    /** @param array<string, mixed> $config */
    protected function configureSearchPath(PDO $connection, array $config): void
    {
        if (isset($config['schema'])) {
            $connection->exec('SET search_path TO ' . $this->quoteSearchPath($config['schema']));
        }
    }

    /** @param array<string, mixed> $config */
    protected function configureTimezone(PDO $connection, array $config): void
    {
        if (isset($config['timezone'])) {
            $connection->exec("SET time zone '" . str_replace("'", "''", $config['timezone']) . "'");
        }
    }

    /**
     * Quote a search path, which may name more than one schema.
     *
     * The value reaches SET as an identifier rather than a bound parameter,
     * so each name is checked against the identifier shape before it is used.
     *
     * @param string|array<int, string> $schema
     */
    protected function quoteSearchPath(string|array $schema): string
    {
        $names = is_array($schema) ? $schema : explode(',', $schema);

        return implode(', ', array_map(static function (string $name): string {
            $name = trim($name);

            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $name)) {
                throw new InvalidArgumentException("Invalid schema name in search path: {$name}");
            }

            return '"' . $name . '"';
        }, $names));
    }
}
