<?php

namespace Nitro\Database\Connectors;

use PDO;

/**
 * Connects to MySQL, and to MariaDB, which takes the same DSN and settings.
 */
class MySqlConnector extends Connector
{
    /**
     * A socket when one is configured, otherwise host and port.
     *
     * Always the mysql: prefix, MariaDB included: PDO has no mariadb driver,
     * and MariaDB speaks the MySQL protocol.
     */
    public function getDsn(array $config): string
    {
        $database = $config['database'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';

        if (! empty($config['unix_socket'])) {
            return "mysql:unix_socket={$config['unix_socket']};dbname={$database};charset={$charset}";
        }

        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 3306;

        return "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
    }

    public function configureConnection(PDO $connection, array $config): void
    {
        $charset = $config['charset'] ?? 'utf8mb4';
        $collation = $config['collation'] ?? 'utf8mb4_unicode_ci';

        $this->assertSafeCharsetAndCollation($charset, $collation);

        $connection->exec("SET NAMES '{$charset}' COLLATE '{$collation}'");
    }
}
