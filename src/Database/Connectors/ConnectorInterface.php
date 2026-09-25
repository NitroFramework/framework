<?php

namespace Nitro\Database\Connectors;

use PDO;

/**
 * Opens a PDO connection for one database driver.
 */
interface ConnectorInterface
{
    /**
     * Open a connection from a connection's config, configured for use.
     *
     * @param array<string, mixed> $config
     */
    public function connect(array $config): PDO;

    /**
     * The DSN PDO is given for this config.
     *
     * @param array<string, mixed> $config
     */
    public function getDsn(array $config): string;

    /**
     * Set up an open connection the way this driver needs: character set,
     * session settings, pragmas.
     *
     * @param array<string, mixed> $config
     */
    public function configureConnection(PDO $connection, array $config): void;
}
