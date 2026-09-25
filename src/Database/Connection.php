<?php

namespace Nitro\Database;

use Closure;
use Nitro\Database\Connectors\ConnectionFactory;
use Nitro\Database\Connectors\ConnectorInterface;
use Nitro\Database\Events\DatabaseEvents;
use Nitro\Database\Events\QueryEvent;
use Nitro\Events\Concerns\DispatchesEvents;
use Nitro\Events\Contracts\ReceivesDispatcher;
use PDO;
use PDOStatement;
use PDOException;
use Generator;

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
     * The connection selects run on, when reads and writes are split: the
     * open PDO, or the closure that opens it on the first read.
     */
    private PDO|Closure|null $readPdo = null;

    /** What {@see disconnect()} puts back, so a read reconnects on demand. */
    private PDO|Closure|null $readPdoResolver = null;

    /** @var array<string, mixed> The read connection's config. */
    private array $readPdoConfig = [];

    /**
     * Whether this connection has written anything since it was last told to
     * forget. With 'sticky' on, a read after a write goes to the write
     * connection, so a request sees its own changes before replication does.
     */
    protected bool $recordsModified = false;

    /** Whether selects are sent to the write connection regardless. */
    protected bool $readOnWriteConnection = false;

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

    /**
     * @param array<string, mixed> $config
     * @param (Closure(): PDO)|null $pdoResolver Opens the PDO on first use, as
     *                                           {@see ConnectionFactory} supplies it.
     *                                           Without one, the connection opens
     *                                           its own through the driver's connector.
     */
    public function __construct(array $config, private ?Closure $pdoResolver = null)
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

    // ─── Read and write connections ───────────────────────

    /**
     * The PDO a select runs on.
     *
     * The write connection when there is no read one, inside a transaction
     * (a read replica cannot see uncommitted rows), when told to read from the
     * write side, or when 'sticky' is on and this connection has written.
     */
    public function getReadPdo(): PDO
    {
        if ($this->pdo !== null && $this->pdo->inTransaction()) {
            return $this->pdo;
        }

        if ($this->readOnWriteConnection || ($this->recordsModified && ($this->config['sticky'] ?? false))) {
            return $this->getPdo();
        }

        if ($this->readPdo instanceof Closure) {
            return $this->readPdo = ($this->readPdo)();
        }

        return $this->readPdo ?? $this->getPdo();
    }

    /**
     * Send selects to another connection: an open PDO, or a closure that
     * opens one on the first read.
     */
    public function setReadPdo(PDO|Closure|null $pdo): static
    {
        $this->readPdo = $pdo;
        $this->readPdoResolver = $pdo;

        return $this;
    }

    /** @param array<string, mixed> $config */
    public function setReadPdoConfig(array $config): static
    {
        $this->readPdoConfig = $config;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getReadPdoConfig(): array
    {
        return $this->readPdoConfig;
    }

    /** Send selects to the write connection until told otherwise. */
    public function useWriteConnectionWhenReading(bool $value = true): static
    {
        $this->readOnWriteConnection = $value;

        return $this;
    }

    /** Note that this connection has written, for 'sticky' reads. */
    public function recordsHaveBeenModified(bool $value = true): void
    {
        if (! $this->recordsModified) {
            $this->recordsModified = $value;
        }
    }

    public function setRecordModificationState(bool $value): static
    {
        $this->recordsModified = $value;

        return $this;
    }

    /**
     * Forget that this connection has written, so reads go back to the read
     * connection. Between requests in a worker, since the connection outlives
     * the request that wrote.
     */
    public function forgetRecordModificationState(): void
    {
        $this->recordsModified = false;
    }

    public function hasModifiedRecords(): bool
    {
        return $this->recordsModified;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    private function connect(): void
    {
        if ($this->pdoResolver !== null) {
            $this->pdo = ($this->pdoResolver)();

            return;
        }

        $connector = $this->connector();

        $this->pdo = $connector->createConnection(
            $this->buildDsn($this->config),
            $this->config,
            $connector->getOptions($this->config),
        );

        $this->afterConnect($this->pdo);
    }

    /**
     * The connector for this connection's driver, MySQL when none is named.
     */
    protected function connector(): ConnectorInterface
    {
        return (new ConnectionFactory())->createConnector($this->config + ['driver' => 'mysql']);
    }

    // ─── Override these per driver ────────────────────────

    /**
     * The DSN to open, as the driver's connector builds it.
     *
     * A seam for a subclass that needs another one, such as a test pointing
     * at an in-memory database.
     *
     * @param array<string, mixed> $config
     */
    protected function buildDsn(array $config): string
    {
        return $this->connector()->getDsn($config);
    }

    /**
     * Set up a freshly opened connection, as the driver's connector does.
     */
    protected function afterConnect(PDO $pdo): void
    {
        $this->connector()->configureConnection($pdo, $this->config);
    }

    // ─── Query Execution ──────────────────────────────────

    /**
     * @param bool $useReadPdo False to read from the write connection, for a
     *                         select that must see what was just written.
     */
    public function select(string $sql, array $bindings = [], bool $useReadPdo = true): array
    {
        return $this->run($sql, $bindings, static function (PDOStatement $stmt) {
            $rows = $stmt->fetchAll();
            // Release the cursor so the cached statement holds no read lock —
            // SQLite refuses DDL (DROP/ALTER) while any cursor is still open.
            $stmt->closeCursor();
            return $rows;
        }, $this->pdoForSelect($useReadPdo));
    }

    public function selectOne(string $sql, array $bindings = [], bool $useReadPdo = true): ?object
    {
        return $this->run($sql, $bindings, static function (PDOStatement $stmt) {
            $row = $stmt->fetch() ?: null;
            // fetch() reads a single row but leaves the result set open; close
            // the cursor so a later DDL statement isn't blocked (SQLite lock).
            $stmt->closeCursor();
            return $row;
        }, $this->pdoForSelect($useReadPdo));
    }

    /**
     * Select from the write connection, whatever the read settings say.
     *
     * @param array<int, mixed> $bindings
     */
    public function selectFromWriteConnection(string $sql, array $bindings = []): array
    {
        return $this->select($sql, $bindings, false);
    }

    /** The PDO a select should run on. */
    protected function pdoForSelect(bool $useReadPdo = true): PDO
    {
        return $useReadPdo ? $this->getReadPdo() : $this->getPdo();
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
     * @return Generator<int, object>
     */
    public function cursor(string $sql, array $bindings = [], bool $useReadPdo = true): Generator
    {
        if ($this->pretending) {
            $this->capturePretend($sql, $bindings);

            return;
        }

        $start = $this->logging ? microtime(true) : 0;

        if (!empty($bindings)) {
            $bindings = $this->prepareBindings($bindings);
        }

        $statement = $this->pdoForSelect($useReadPdo)->prepare($sql);

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
        return $this->run($sql, $bindings, function () {
            $this->recordsHaveBeenModified();

            return true;
        });
    }

    public function insertGetId(string $sql, array $bindings = []): int
    {
        if ($this->pretending) { $this->capturePretend($sql, $bindings); return 0; }
        return $this->run($sql, $bindings, function () {
            $this->recordsHaveBeenModified();

            return (int) $this->getPdo()->lastInsertId();
        });
    }

    public function update(string $sql, array $bindings = []): int
    {
        if ($this->pretending) { $this->capturePretend($sql, $bindings); return 0; }
        return $this->affectingStatement($sql, $bindings);
    }

    public function delete(string $sql, array $bindings = []): int
    {
        if ($this->pretending) { $this->capturePretend($sql, $bindings); return 0; }
        return $this->affectingStatement($sql, $bindings);
    }

    public function statement(string $sql, array $bindings = []): bool
    {
        if ($this->pretending) { $this->capturePretend($sql, $bindings); return true; }
        return $this->run($sql, $bindings, function () {
            $this->recordsHaveBeenModified();

            return true;
        });
    }

    /**
     * Run a statement and return how many rows it changed, noting a write
     * only when it changed any.
     *
     * @param array<int, mixed> $bindings
     */
    private function affectingStatement(string $sql, array $bindings): int
    {
        return $this->run($sql, $bindings, function (PDOStatement $stmt): int {
            $count = $stmt->rowCount();

            $this->recordsHaveBeenModified($count > 0);

            return $count;
        });
    }

    /** Append a captured-but-not-executed query to the pretend log. */
    private function capturePretend(string $sql, array $bindings): void
    {
        $this->pretendLog[] = ['sql' => $sql, 'bindings' => $bindings];
    }

    /**
     * @param PDO|null $pdo The connection to run on; the write one when null.
     */
    private function run(string $sql, array $bindings, callable $callback, ?PDO $pdo = null): mixed
    {
        $pdo ??= $this->getPdo();

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
            $stmt = $this->prepareCached($sql, $pdo);
            $this->bindAndExecute($stmt, $bindings);
            $result = $callback($stmt);
        } catch (PDOException $exception) {
            // Drop the cached statement — it may be in an unusable state.
            unset($this->statementCache[$this->statementCacheKey($sql, $pdo)]);
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
    private function prepareCached(string $sql, PDO $pdo): PDOStatement
    {
        $key = $this->statementCacheKey($sql, $pdo);

        if (isset($this->statementCache[$key])) {
            $stmt = $this->statementCache[$key];
            // Refresh LRU position.
            unset($this->statementCache[$key]);
            $this->statementCache[$key] = $stmt;
            return $stmt;
        }

        $stmt = $pdo->prepare($sql);

        if (count($this->statementCache) >= $this->statementCacheLimit) {
            // Drop the oldest entry.
            array_shift($this->statementCache);
        }
        $this->statementCache[$key] = $stmt;

        return $stmt;
    }

    /**
     * A statement belongs to the PDO that prepared it, so with reads and
     * writes split the same SQL is cached once per connection. The write
     * connection keeps the bare SQL as its key.
     */
    private function statementCacheKey(string $sql, PDO $pdo): string
    {
        return $pdo === $this->pdo ? $sql : 'read:' . $sql;
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
        $this->readPdo = $this->readPdoResolver instanceof Closure ? $this->readPdoResolver : null;
    }
}
