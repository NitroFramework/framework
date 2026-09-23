<?php

namespace Nitro\Database;

use Closure;
use Nitro\Database\Events\DatabaseEvents;
use Nitro\Database\Events\QueryEvent;
use Nitro\Events\Concerns\DispatchesEvents;
use Nitro\Events\Contracts\ReceivesDispatcher;
use PDO;
use PDOStatement;
use PDOException;

/**
 * A database connection — wraps PDO and runs queries, statements and transactions.
 */
class Connection implements ReceivesDispatcher
{
    use DispatchesEvents;

    private ?PDO $pdo = null;
    protected array $config;
    private array $log = [];
    private bool $logging = false;

    /**
     * Prepared-statement cache keyed by SQL text. Same query repeated in a
     * loop reuses the same PDOStatement, which avoids a network round-trip
     * to MySQL per execution (with EMULATE_PREPARES=false). Bounded LRU so
     * a long-running worker can't grow this without limit.
     */
    private array $statementCache = [];
    private int $statementCacheLimit = 256;

    /**
     * When pretending, write queries (statement/insert/update/delete) are
     * captured into $pretendLog instead of running. Read queries (select)
     * still execute for real — schema-introspection paths like hasTable()
     * MUST see truth, otherwise migrate:run --pretend would loop forever
     * thinking the migrations table doesn't exist.
     */
    private bool $pretending = false;
    private array $pretendLog = [];

    /**
     * Run $callback in pretend mode. Returns the captured SQL log so
     * the caller can render or assert on it. Nested calls are flattened
     * — the outermost call gets the full transcript.
     *
     *   $log = DB::connection()->pretending(function () use ($migration, $schema) {
     *       $migration->up($schema);
     *   });
     *   foreach ($log as $row) echo $row['sql'] . PHP_EOL;
     */
    public function pretending(callable $callback): array
    {
        $alreadyPretending = $this->pretending;
        if (!$alreadyPretending) {
            $this->pretending = true;
            $this->pretendLog = [];
        }
        try {
            $callback();
            return $this->pretendLog;
        } finally {
            if (!$alreadyPretending) {
                $this->pretending = false;
            }
        }
    }

    public function isPretending(): bool
    {
        return $this->pretending;
    }

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $this->connect();
        }
        return $this->pdo;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    private function connect(): void
    {
        $dsn = $this->buildDsn($this->config);
        $username = $this->config['username'] ?? 'root';
        $password = $this->config['password'] ?? '';

        $this->pdo = new PDO($dsn, $username, $password, $this->getDefaultOptions());

        $this->afterConnect($this->pdo);
    }

    // ─── Override these per driver ────────────────────────

    protected function buildDsn(array $config): string
    {
        $driver   = $config['driver'] ?? 'mysql';

        // SQLite is a single file (or an in-memory database) — no host/port/charset.
        if ($driver === 'sqlite') {
            $database = (string) ($config['database'] ?? '');
            return $database === ':memory:' ? 'sqlite::memory:' : "sqlite:{$database}";
        }

        $database = $config['database'] ?? '';

        // Postgres takes neither charset nor collation in the DSN; the client
        // encoding is set once the connection is open, and sslmode belongs here
        // because libpq reads it while connecting.
        if ($driver === 'pgsql') {
            $host = $config['host'] ?? '127.0.0.1';
            $port = $config['port'] ?? 5432;

            $dsn = "pgsql:host={$host};port={$port};dbname={$database}";

            if (isset($config['sslmode'])) {
                $dsn .= ";sslmode={$config['sslmode']}";
            }

            return $dsn;
        }

        $host     = $config['host'] ?? '127.0.0.1';
        $port     = $config['port'] ?? 3306;
        $charset  = $config['charset'] ?? 'utf8mb4';

        return "{$driver}:host={$host};port={$port};dbname={$database};charset={$charset}";
    }

    protected function afterConnect(PDO $pdo): void
    {
        $driver = $this->config['driver'] ?? 'mysql';

        // SQLite has no SET NAMES; enforce foreign keys, which it leaves off by default.
        if ($driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
            return;
        }

        // Postgres spells these differently and has no COLLATE on the session:
        // a collation belongs to a column or a comparison, not a connection.
        if ($driver === 'pgsql') {
            $charset = $this->config['charset'] ?? 'utf8';

            $this->assertSafeCharsetAndCollation($charset, 'utf8');

            $pdo->exec("SET NAMES '{$charset}'");

            if (isset($this->config['schema'])) {
                $pdo->exec('SET search_path TO ' . $this->quoteSearchPath($this->config['schema']));
            }

            if (isset($this->config['timezone'])) {
                $pdo->exec("SET time zone '" . str_replace("'", "''", $this->config['timezone']) . "'");
            }

            return;
        }

        $charset   = $this->config['charset'] ?? 'utf8mb4';
        $collation = $this->config['collation'] ?? 'utf8mb4_unicode_ci';

        $this->assertSafeCharsetAndCollation($charset, $collation);

        $pdo->exec("SET NAMES '{$charset}' COLLATE '{$collation}'");
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

            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $name)) {
                throw new \InvalidArgumentException("Invalid schema name in search path: {$name}");
            }

            return '"' . $name . '"';
        }, $names));
    }

    /**
     * Validate that charset and collation only contain identifier-safe
     * characters before interpolation into SET NAMES. Extracted from
     * afterConnect() so it can be unit-tested without a real PDO instance.
     */
    protected function assertSafeCharsetAndCollation(string $charset, string $collation): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $charset)
            || !preg_match('/^[A-Za-z0-9_]+$/', $collation)) {
            throw new \InvalidArgumentException('Invalid charset or collation in database config.');
        }
    }

    protected function getDefaultOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
    }

    // ─── Query Execution ──────────────────────────────────

    public function select(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings, static function (PDOStatement $stmt) {
            $rows = $stmt->fetchAll();
            // Release the cursor so the cached statement holds no read lock —
            // SQLite refuses DDL (DROP/ALTER) while any cursor is still open.
            $stmt->closeCursor();
            return $rows;
        });
    }

    public function selectOne(string $sql, array $bindings = []): ?object
    {
        return $this->run($sql, $bindings, static function (PDOStatement $stmt) {
            $row = $stmt->fetch() ?: null;
            // fetch() reads a single row but leaves the result set open; close
            // the cursor so a later DDL statement isn't blocked (SQLite lock).
            $stmt->closeCursor();
            return $row;
        });
    }

    /**
     * Read a result set one row at a time.
     *
     * The statement stays open while the caller iterates, so it is prepared
     * outside the statement cache: a cached statement is shared, and a second
     * query reaching for it mid-iteration would reset the result set out from
     * under this loop.
     *
     * @param  array<int, mixed> $bindings
     * @return \Generator<int, object>
     */
    public function cursor(string $sql, array $bindings = []): \Generator
    {
        if ($this->pretending) {
            $this->capturePretend($sql, $bindings);

            return;
        }

        $start = $this->logging ? microtime(true) : 0;

        if (!empty($bindings)) {
            $bindings = $this->prepareBindings($bindings);
        }

        $statement = $this->getPdo()->prepare($sql);

        try {
            $this->bindAndExecute($statement, $bindings);

            while ($row = $statement->fetch()) {
                yield $row;
            }
        } catch (PDOException $exception) {
            throw new PDOException(
                $exception->getMessage() . " (SQL: {$sql}) (Bindings: " . self::formatBindings($bindings) . ")",
                (int) $exception->getCode(),
                $exception
            );
        } finally {
            $statement->closeCursor();

            if ($this->logging) {
                $this->log[] = [
                    'sql' => $sql,
                    'bindings' => $bindings,
                    'time' => round((microtime(true) - $start) * 1000, 2),
                ];
            }
        }
    }

    public function insert(string $sql, array $bindings = []): bool
    {
        if ($this->pretending) { $this->capturePretend($sql, $bindings); return true; }
        return $this->run($sql, $bindings, static function () {
            return true;
        });
    }

    public function insertGetId(string $sql, array $bindings = []): int
    {
        if ($this->pretending) { $this->capturePretend($sql, $bindings); return 0; }
        return $this->run($sql, $bindings, function () {
            return (int) $this->getPdo()->lastInsertId();
        });
    }

    public function update(string $sql, array $bindings = []): int
    {
        if ($this->pretending) { $this->capturePretend($sql, $bindings); return 0; }
        return $this->run($sql, $bindings, static function (PDOStatement $stmt) {
            return $stmt->rowCount();
        });
    }

    public function delete(string $sql, array $bindings = []): int
    {
        if ($this->pretending) { $this->capturePretend($sql, $bindings); return 0; }
        return $this->run($sql, $bindings, static function (PDOStatement $stmt) {
            return $stmt->rowCount();
        });
    }

    public function statement(string $sql, array $bindings = []): bool
    {
        if ($this->pretending) { $this->capturePretend($sql, $bindings); return true; }
        return $this->run($sql, $bindings, static function () {
            return true;
        });
    }

    /** Append a captured-but-not-executed query to the pretend log. */
    private function capturePretend(string $sql, array $bindings): void
    {
        $this->pretendLog[] = ['sql' => $sql, 'bindings' => $bindings];
    }

    private function run(string $sql, array $bindings, callable $callback): mixed
    {
        /*
         * One clock read, shared by the query log and query.executed, and
         * taken only if one of them will use it. This is the hottest path in
         * the framework — a page doing forty queries runs it forty times.
         */
        $timed = $this->logging || $this->listeningFor(DatabaseEvents::QUERY_EXECUTED);
        $start = $timed ? microtime(true) : 0;

        if (!empty($bindings)) {
            $bindings = $this->prepareBindings($bindings);
        }

        /**
         * Emit point — query.executing
         *
         * Before the statement runs, on every query the framework issues —
         * the single funnel select(), selectOne() and statement() all pass
         * through. Fires whether or not the query succeeds, so a listener
         * counting queries must not assume a matching query.executed.
         * Payload: {@see QueryEvent}.
         */
        $this->eventLazy(
            DatabaseEvents::QUERY_EXECUTING,
            fn (): QueryEvent => new QueryEvent($sql, $bindings),
        );

        try {
            $stmt = $this->prepareCached($sql);
            $this->bindAndExecute($stmt, $bindings);
            $result = $callback($stmt);
        } catch (PDOException $exception) {
            // Drop the cached statement — it may be in an unusable state.
            unset($this->statementCache[$sql]);
            throw new PDOException(
                $exception->getMessage() . " (SQL: {$sql}) (Bindings: " . self::formatBindings($bindings) . ")",
                (int) $exception->getCode(),
                $exception
            );
        }

        $elapsed = $timed ? round((microtime(true) - $start) * 1000, 2) : 0.0;

        if ($this->logging) {
            $this->log[] = [
                'sql' => $sql,
                'bindings' => $bindings,
                'time' => $elapsed,
            ];
        }

        /**
         * Emit point — query.executed
         *
         * After the statement ran and the result was read, with how long it
         * took in milliseconds. This is where a slow-query log belongs. Does
         * not fire when the query threw — that surfaces as an exception, not
         * as an executed query.
         * Payload: {@see QueryEvent}.
         */
        $this->eventLazy(
            DatabaseEvents::QUERY_EXECUTED,
            fn (): QueryEvent => new QueryEvent($sql, $bindings, $elapsed),
        );

        return $result;
    }

    /**
     * Whether anything is listening for an event, without building a payload.
     *
     * Lets a caller skip work that only an event would need — a clock read on
     * a hot path — rather than paying for it on every query in case somebody
     * is watching.
     */
    protected function listeningFor(string $event): bool
    {
        return $this->dispatcher !== null && $this->dispatcher->hasListeners($event);
    }

    /**
     * Raise a database lifecycle event on this connection's bus.
     *
     * Public so {@see \Nitro\Database\Query\Transaction} can use it. Its work
     * runs through this connection and belongs to the same event stream, and
     * giving it separate wiring for three events that only happen here would
     * be a second thing to remember to connect.
     */
    public function raise(string $event, Closure $payload): void
    {
        $this->eventLazy($event, $payload);
    }

    /**
     * Get a prepared PDOStatement for $sql, reusing a cached one if we've
     * seen this exact SQL before. When the cache fills, the oldest entry
     * is evicted (PHP arrays preserve insertion order, so unset+re-insert
     * gives LRU semantics).
     *
     * Pretend mode bypasses the cache — pretending queries don't execute,
     * so caching the prepare round-trip there has no value.
     */
    private function prepareCached(string $sql): PDOStatement
    {
        if (isset($this->statementCache[$sql])) {
            $stmt = $this->statementCache[$sql];
            // Refresh LRU position.
            unset($this->statementCache[$sql]);
            $this->statementCache[$sql] = $stmt;
            return $stmt;
        }

        $stmt = $this->getPdo()->prepare($sql);

        if (count($this->statementCache) >= $this->statementCacheLimit) {
            // Drop the oldest entry.
            array_shift($this->statementCache);
        }
        $this->statementCache[$sql] = $stmt;

        return $stmt;
    }

    /**
     * Render bindings as a short string for error messages without leaking
     * binary blobs. Used by the PDOException re-throw — adds enough context
     * to debug FK violations / constraint errors without enabling full
     * query logging.
     */
    private static function formatBindings(array $bindings): string
    {
        if (empty($bindings)) {
            return '[]';
        }
        $parts = [];
        foreach ($bindings as $binding) {
            if (is_string($binding) && strlen($binding) > 64) {
                $parts[] = '"' . substr($binding, 0, 64) . '…"';
            } elseif (is_string($binding)) {
                $parts[] = '"' . $binding . '"';
            } elseif ($binding === null) {
                $parts[] = 'null';
            } elseif (is_bool($binding)) {
                $parts[] = $binding ? 'true' : 'false';
            } else {
                $parts[] = (string) $binding;
            }
        }
        return '[' . implode(', ', $parts) . ']';
    }

    /**
     * Normalize binding values before PDO sees them.
     *
     * PDO's default type inference is lossy in two spots that bite real apps:
     *   - bool false → '' (empty string), which MySQL rejects in strict mode
     *     on numeric columns. Cast to int so true/false become 1/0.
     *   - DateTimeInterface → no built-in handling. Format as 'Y-m-d H:i:s'
     *     so it survives as a DATETIME/TIMESTAMP value.
     *
     * Anything else (null, scalars, strings) passes through unchanged. Caller
     * guards against empty bindings so this never runs for a select with no
     * wheres — that's most of the simple-find traffic.
     */
    /**
     * Bind the parameters and run the statement.
     *
     * Each value is bound with its own type rather than handed to execute()
     * as an array, which sends everything as a string. A string is usually
     * harmless — the column's affinity converts it back — but an expression
     * has no affinity to convert with, so json_array_length(...) > '1' on
     * SQLite compares a number against text and matches nothing.
     *
     * @param array<int|string, mixed> $bindings
     */
    private function bindAndExecute(PDOStatement $statement, array $bindings): void
    {
        if ($bindings === []) {
            $statement->execute();

            return;
        }

        $position = 1;

        foreach ($bindings as $key => $value) {
            $statement->bindValue(
                is_string($key) ? $key : $position++,
                $value,
                match (true) {
                    $value === null => PDO::PARAM_NULL,
                    is_int($value) => PDO::PARAM_INT,
                    is_bool($value) => PDO::PARAM_BOOL,
                    is_resource($value) => PDO::PARAM_LOB,
                    default => PDO::PARAM_STR,
                }
            );
        }

        $statement->execute();
    }

    private function prepareBindings(array $bindings): array
    {
        foreach ($bindings as $key => $value) {
            if (is_bool($value)) {
                $bindings[$key] = (int) $value;
            } elseif ($value instanceof \DateTimeInterface) {
                $bindings[$key] = $value->format('Y-m-d H:i:s');
            } elseif ($value instanceof \BackedEnum) {
                // where('status', OrderStatus::Paid) is the natural thing to
                // write once a column casts to an enum, and PDO cannot bind an
                // object. Unwrap here rather than at every call site.
                $bindings[$key] = $value->value;
            }
        }
        return $bindings;
    }

    // ─── Statement cache control ──────────────────────────

    /**
     * Drop every cached prepared statement. Called after schema changes
     * (migrations) since the cached statement may reference columns that
     * no longer exist.
     */
    public function flushStatementCache(): void
    {
        $this->statementCache = [];
    }

    /** Tune the LRU cap. 0 disables caching entirely. */
    public function setStatementCacheLimit(int $limit): void
    {
        $this->statementCacheLimit = max(0, $limit);
        while (count($this->statementCache) > $this->statementCacheLimit) {
            array_shift($this->statementCache);
        }
    }

    public function getStatementCacheSize(): int
    {
        return count($this->statementCache);
    }

    // ─── Logging ──────────────────────────────────────────

    public function enableLogging(): void
    {
        $this->logging = true;
    }

    public function getLog(): array
    {
        return $this->log;
    }

    public function disconnect(): void
    {
        $this->statementCache = [];
        $this->pdo = null;
    }
}
