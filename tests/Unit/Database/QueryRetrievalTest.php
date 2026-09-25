<?php

namespace Tests\Unit\Database;

use BadMethodCallException;
use Nitro\Database\Connection;
use Nitro\Database\Query\Exceptions\RecordsNotFoundException;
use Nitro\Database\Query\Grammar\SqliteGrammar;
use Nitro\Database\Query\QueryBuilder;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Getting rows out, reusing a query, and adding a method to the builder.
 */
class QueryRetrievalTest extends TestCase
{
    /** @param array<int, object> $rows */
    private function builder(array $rows = []): QueryBuilder
    {
        $connection = new class($rows) extends Connection {
            /** @param array<int, object> $rows */
            public function __construct(private array $rows = [])
            {
            }

            public function select(string $sql, array $bindings = [], bool $useReadPdo = true): array
            {
                return $this->rows;
            }
        };

        return (new QueryBuilder($connection, new SqliteGrammar()))->table('orders');
    }

    private function sqlOf(QueryBuilder $query): string
    {
        return (new SqliteGrammar())->compileSelect($query);
    }

    // ─── firstOrFail ──────────────────────────────────────

    public function test_first_or_fail_throws_and_names_the_table(): void
    {
        $this->expectException(RecordsNotFoundException::class);
        $this->expectExceptionMessageMatches('/orders/');

        $this->builder()->firstOrFail();
    }

    public function test_first_or_fail_returns_the_row_when_there_is_one(): void
    {
        $row = $this->builder([(object) ['id' => 7]])->firstOrFail();

        $this->assertSame(7, $row->id);
    }

    // ─── Reusing a query ──────────────────────────────────

    public function test_clone_does_not_share_later_clauses(): void
    {
        $base = $this->builder()->where('status', 'paid');

        $narrowed = $base->clone()->where('total', '>', 100);
        $untouched = $base->clone();

        $this->assertSame(
            'SELECT * FROM "orders" WHERE "status" = ? AND "total" > ?',
            $this->sqlOf($narrowed),
        );

        $this->assertSame(
            'SELECT * FROM "orders" WHERE "status" = ?',
            $this->sqlOf($untouched),
        );
    }

    public function test_without_clone_the_clauses_accumulate(): void
    {
        $base = $this->builder()->where('status', 'paid');
        $base->where('total', '>', 100);

        $this->assertSame(
            'SELECT * FROM "orders" WHERE "status" = ? AND "total" > ?',
            $this->sqlOf($base),
        );
    }

    public function test_pipe_hands_over_the_builder(): void
    {
        $sql = $this->builder()->pipe(static fn (QueryBuilder $query): string => $query->toSql());

        $this->assertSame('SELECT * FROM "orders"', $sql);
    }

    // ─── chunkMap ─────────────────────────────────────────

    public function test_chunk_map_collects_across_chunks(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite required');
        }

        $connection = new class(['driver' => 'sqlite', 'database' => ':memory:']) extends Connection {
            protected function buildDsn(array $config): string
            {
                return 'sqlite::memory:';
            }

            protected function afterConnect(PDO $pdo): void
            {
            }
        };

        $connection->statement('CREATE TABLE orders (id INTEGER PRIMARY KEY, total INTEGER)');

        foreach (range(1, 7) as $id) {
            $connection->statement('INSERT INTO orders (id, total) VALUES (?, ?)', [$id, $id * 10]);
        }

        $totals = (new QueryBuilder($connection, new SqliteGrammar()))
            ->table('orders')
            ->orderBy('id')
            ->chunkMap(static fn (object $row): int => (int) $row->total, 2);

        $this->assertSame([10, 20, 30, 40, 50, 60, 70], $totals->all());
    }

    // ─── Macros ───────────────────────────────────────────

    public function test_a_macro_can_be_added_and_called(): void
    {
        QueryBuilder::macro(
            'onlyPaid',
            static fn (QueryBuilder $query): QueryBuilder => $query->where('status', 'paid'),
        );

        try {
            $this->assertSame(
                'SELECT * FROM "orders" WHERE "status" = ?',
                $this->sqlOf(QueryBuilder::onlyPaid($this->builder())),
            );
        } finally {
            QueryBuilder::flushMacros();
        }
    }

    public function test_a_dynamic_where_still_wins_over_a_macro_of_the_same_name(): void
    {
        QueryBuilder::macro('whereStatusAndTotal', static fn (): string => 'the macro ran');

        try {
            $this->assertSame(
                'SELECT * FROM "orders" WHERE "status" = ? AND "total" = ?',
                $this->sqlOf($this->builder()->whereStatusAndTotal('paid', 100)),
            );
        } finally {
            QueryBuilder::flushMacros();
        }
    }

    public function test_an_unknown_method_still_says_so(): void
    {
        $this->expectException(BadMethodCallException::class);

        $this->builder()->definitelyNotAMethod();
    }
}
