<?php

namespace Nitro\Database\Connectors;

use Nitro\Database\SQLiteDatabaseDoesNotExistException;
use PDO;
use Throwable;

/**
 * Connects to SQLite: a single file, or an in-memory database.
 */
class SQLiteConnector extends Connector
{
    /**
     * @throws SQLiteDatabaseDoesNotExistException When the file is not there.
     */
    public function getDsn(array $config): string
    {
        return 'sqlite:' . $this->parseDatabasePath((string) ($config['database'] ?? ''));
    }

    /**
     * The absolute path to the database, checked to exist.
     *
     * An in-memory database — ':memory:', a URI naming mode=memory, or any
     * 'file:' URI — passes as given. A relative path is tried from the working
     * directory, then from the application's base. PDO would otherwise create
     * an empty file at a mistyped path and connect to it.
     *
     * @throws SQLiteDatabaseDoesNotExistException
     */
    protected function parseDatabasePath(string $path): string
    {
        $database = $path;

        if ($path === ':memory:'
            || str_contains($path, '?mode=memory')
            || str_contains($path, '&mode=memory')
            || str_starts_with($path, 'file:')
        ) {
            return $path;
        }

        $path = realpath($path) ?: $this->realpathFromBase($path);

        if ($path === false) {
            throw new SQLiteDatabaseDoesNotExistException($database);
        }

        return $path;
    }

    /** The path read from the application's base, when there is an application. */
    private function realpathFromBase(string $path): string|false
    {
        if (! function_exists('base_path')) {
            return false;
        }

        try {
            return realpath(base_path($path));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Foreign keys on, which SQLite leaves off by default, unless the config
     * says otherwise; then any pragmas the config names.
     */
    public function configureConnection(PDO $connection, array $config): void
    {
        $foreignKeys = ($config['foreign_key_constraints'] ?? true) ? 1 : 0;

        $connection->exec("PRAGMA foreign_keys = {$foreignKeys}");

        $this->configurePragma($connection, 'busy_timeout', $config['busy_timeout'] ?? null);
        $this->configurePragma($connection, 'journal_mode', $config['journal_mode'] ?? null);
        $this->configurePragma($connection, 'synchronous', $config['synchronous'] ?? null);

        foreach ($config['pragmas'] ?? [] as $pragma => $value) {
            $this->configurePragma($connection, $pragma, $value);
        }
    }

    protected function configurePragma(PDO $connection, string $pragma, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        $connection->exec("PRAGMA {$pragma} = {$value}");
    }
}
