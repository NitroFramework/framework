<?php

namespace Nitro\Database;

use PDO;
use PDOStatement;
use PDOException;

/**
 * A database connection — wraps PDO and runs queries, statements and transactions.
 */
class Connection
{
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

        $host     = $config['host'] ?? '127.0.0.1';
        $port     = $config['port'] ?? 3306;
        $database = $config['database'] ?? '';
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

        $charset   = $this->config['charset'] ?? 'utf8mb4';
        $collation = $this->config['collation'] ?? 'utf8mb4_unicode_ci';

        $this->assertSafeCharsetAndCollation($charset, $collation);

        $pdo->exec("SET NAMES '{$charset}' COLLATE '{$collation}'");
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
            return $stmt->fetchAll();
        });
    }

    public function selectOne(string $sql, array $bindings = []): ?object
    {
        return $this->run($sql, $bindings, static function (PDOStatement $stmt) {
            return $stmt->fetch() ?: null;
        });
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
        $start = $this->logging ? microtime(true) : 0;

        if (!empty($bindings)) {
            $bindings = $this->prepareBindings($bindings);
        }

        try {
            $stmt = $this->prepareCached($sql);
            $stmt->execute($bindings);
            $result = $callback($stmt);
        } catch (PDOException $e) {
            // Drop the cached statement — it may be in an unusable state.
            unset($this->statementCache[$sql]);
            throw new PDOException(
                $e->getMessage() . " (SQL: {$sql}) (Bindings: " . self::formatBindings($bindings) . ")",
                (int) $e->getCode(),
                $e
            );
        }

        if ($this->logging) {
            $this->log[] = [
                'sql' => $sql,
                'bindings' => $bindings,
                'time' => round((microtime(true) - $start) * 1000, 2),
            ];
        }

        return $result;
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
        foreach ($bindings as $b) {
            if (is_string($b) && strlen($b) > 64) {
                $parts[] = '"' . substr($b, 0, 64) . '…"';
            } elseif (is_string($b)) {
                $parts[] = '"' . $b . '"';
            } elseif ($b === null) {
                $parts[] = 'null';
            } elseif (is_bool($b)) {
                $parts[] = $b ? 'true' : 'false';
            } else {
                $parts[] = (string) $b;
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
    private function prepareBindings(array $bindings): array
    {
        foreach ($bindings as $key => $value) {
            if (is_bool($value)) {
                $bindings[$key] = (int) $value;
            } elseif ($value instanceof \DateTimeInterface) {
                $bindings[$key] = $value->format('Y-m-d H:i:s');
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
