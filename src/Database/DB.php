<?php

namespace Nitro\Database;

use Closure;
use Nitro\Database\Query\Grammar\Grammar;
use Nitro\Database\Query\Grammar\MySqlGrammar;
use Nitro\Database\Query\QueryBuilder;
use Nitro\Database\Query\Transaction;
use Nitro\Database\Query\RawExpression;

/**
 * The database facade and query entry point.
 *
 * Configures the connection and exposes the developer-facing surface of the
 * Database layer — fluent query builders (DB::table(...)), raw statements,
 * expressions and transactions — over the underlying Connection.
 */
class DB
{
    private static ?Connection $connection = null;
    private static ?Transaction $transaction = null;
    private static ?array $config = null;
    private static ?Grammar $grammar = null;

    /**
     * Pick the grammar matching the configured driver. MySQL is the only
     * one shipped with a custom grammar today — other drivers fall back
     * to the base Grammar (standard-SQL features only, no upsert/lock).
     * Resolved once and cached; configure() resets it.
     */
    public static function grammar(): Grammar
    {
        if (static::$grammar !== null) {
            return static::$grammar;
        }

        $driver = static::$config['driver'] ?? null;
        if ($driver === null) {
            // Best-effort: peek at the (possibly nested) config without
            // forcing a connection.
            $cfg = static::$config;
            if (!$cfg && file_exists(base_path('config/database.php'))) {
                $cfg = require base_path('config/database.php');
            }
            if (is_array($cfg)) {
                $default = $cfg['default'] ?? 'mysql';
                $resolved = $cfg['connections'][$default] ?? $cfg;
                $driver = $resolved['driver'] ?? 'mysql';
            } else {
                $driver = 'mysql';
            }
        }

        return static::$grammar = match ($driver) {
            'mysql', 'mariadb' => new MySqlGrammar(),
            default            => new Grammar(),
        };
    }

    public static function connection(): Connection
    {
        if (static::$connection === null) {
            $config = static::$config ?? require base_path('config/database.php');

            // Resolve the nested connection config
            $default = $config['default'] ?? 'mysql';
            $resolved = $config['connections'][$default] ?? $config;

            static::$connection = new Connection($resolved);
        }
        return static::$connection;
    }

    public static function configure(array $config): void
    {
        // Support both flat and nested config
        if (isset($config['connections'])) {
            $default = $config['default'] ?? 'mysql';
            $config = $config['connections'][$default];
        }

        static::$config = $config;
        static::$connection = null;
        static::$transaction = null;
        static::$grammar = null; // driver may have changed
    }

    // ─── Query Builder Entry Point ────────────────────────

    public static function table(string $table): QueryBuilder
    {
        $builder = new QueryBuilder(static::connection(), static::grammar());
        return $builder->table($table);
    }

    // ─── Raw Expression ───────────────────────────────────

    public static function raw(string $expression, array $bindings = []): RawExpression
    {
        return new RawExpression($expression, $bindings);
    }

    // ─── Raw Queries ──────────────────────────────────────
    // forward raw query methods to the connection for convenience

    public static function select(string $sql, array $bindings = []): array
    {
        return static::connection()->select($sql, $bindings);
    }

    public static function selectOne(string $sql, array $bindings = []): ?object
    {
        return static::connection()->selectOne($sql, $bindings);
    }

    public static function insert(string $sql, array $bindings = []): bool
    {
        return static::connection()->insert($sql, $bindings);
    }

    public static function update(string $sql, array $bindings = []): int
    {
        return static::connection()->update($sql, $bindings);
    }

    public static function delete(string $sql, array $bindings = []): int
    {
        return static::connection()->delete($sql, $bindings);
    }

    public static function statement(string $sql, array $bindings = []): bool
    {
        return static::connection()->statement($sql, $bindings);
    }

    // ─── Transactions ─────────────────────────────────────

    private static function transactor(): Transaction
    {
        if (static::$transaction === null) {
            static::$transaction = new Transaction(static::connection());
        }
        return static::$transaction;
    }

    public static function transaction(Closure $callback): mixed
    {
        return static::transactor()->transaction($callback);
    }

    public static function beginTransaction(): void
    {
        static::transactor()->begin();
    }

    public static function commit(): void
    {
        static::transactor()->commit();
    }

    public static function rollBack(): void
    {
        static::transactor()->rollBack();
    }

    // ─── Utility ──────────────────────────────────────────

    public static function enableLogging(): void
    {
        static::connection()->enableLogging();
    }

    public static function getQueryLog(): array
    {
        return static::connection()->getLog();
    }

    public static function disconnect(): void
    {
        static::connection()->disconnect();
        static::$connection = null;
        static::$transaction = null;
    }
}
