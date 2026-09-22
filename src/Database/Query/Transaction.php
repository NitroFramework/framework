<?php

namespace Nitro\Database\Query;

use Nitro\Database\Connection;
use Nitro\Database\Events\DatabaseEvents;
use Nitro\Database\Events\TransactionEvent;
use Closure;
use Throwable;

/**
 * Nested transaction support via SAVEPOINTs. The level counter only
 * advances/retreats on a SUCCESSFUL PDO operation — if commit() or
 * rollBack() throws, the level stays in sync with the underlying
 * connection state instead of drifting.
 */
class Transaction
{
    private Connection $connection;
    private int $transactionLevel = 0;

    /**
     * Work to run once the outermost transaction commits.
     *
     * Keyed by the level it was registered at, so a callback added inside a
     * savepoint that is rolled back is discarded with it while one added at
     * the outer level survives.
     *
     * @var array<int, list<Closure>>
     */
    private array $committedCallbacks = [];

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Run a callback once the data it depends on is durable.
     *
     * Anything that cannot be rolled back — an email, a queued job, a
     * webhook — has to wait for the commit, because the alternative is
     * telling the world about a row that no longer exists. Outside a
     * transaction there is nothing to wait for and it runs immediately.
     */
    public function addCallback(Closure $callback): void
    {
        if ($this->transactionLevel === 0) {
            $callback();

            return;
        }

        $this->committedCallbacks[$this->transactionLevel][] = $callback;
    }

    /**
     * Run and clear the callbacks registered at or below the current level.
     *
     * Each is run inside its own try: one that throws must not stop the rest,
     * and by this point the transaction is committed — there is nothing left
     * to undo, so the failure belongs in the log rather than up the stack.
     */
    private function runCommittedCallbacks(): void
    {
        $callbacks = $this->committedCallbacks;

        $this->committedCallbacks = [];

        foreach ($callbacks as $level) {
            foreach ($level as $callback) {
                try {
                    $callback();
                } catch (Throwable $exception) {
                    error_log('[db] after-commit callback threw: ' . $exception->getMessage());
                }
            }
        }
    }

    public function begin(): void
    {
        if ($this->transactionLevel === 0) {
            $this->connection->getPdo()->beginTransaction();
        } else {
            $this->connection->statement("SAVEPOINT trans_{$this->transactionLevel}");
        }
        // Only increment after the BEGIN/SAVEPOINT succeeded.
        $this->transactionLevel++;

        /**
         * Emit point — transaction.beginning
         *
         * After the BEGIN or SAVEPOINT succeeded. Raised for a nested
         * transaction too, where the level is above 1 and the underlying
         * driver saw a SAVEPOINT rather than a BEGIN.
         * Payload: {@see TransactionEvent}.
         */
        $this->connection->raise(
            DatabaseEvents::TRANSACTION_BEGINNING,
            fn (): TransactionEvent => new TransactionEvent($this->transactionLevel),
        );
    }

    public function commit(): void
    {
        if ($this->transactionLevel === 0) {
            throw new \LogicException('No active transaction to commit.');
        }

        if ($this->transactionLevel === 1) {
            $this->connection->getPdo()->commit();
        } else {
            $this->connection->statement("RELEASE SAVEPOINT trans_" . ($this->transactionLevel - 1));
        }
        $this->transactionLevel--;

        /**
         * Emit point — transaction.committed
         *
         * After the COMMIT or RELEASE SAVEPOINT succeeded. A level above 0
         * means an inner transaction was released and the outer one is still
         * open — the data is not durable yet.
         * Payload: {@see TransactionEvent}.
         */
        $this->connection->raise(
            DatabaseEvents::TRANSACTION_COMMITTED,
            fn (): TransactionEvent => new TransactionEvent($this->transactionLevel),
        );

        // Only the outermost commit makes anything durable: a released
        // savepoint is still inside a transaction that can roll back.
        if ($this->transactionLevel === 0) {
            $this->runCommittedCallbacks();
        }
    }

    public function rollBack(): void
    {
        if ($this->transactionLevel === 0) {
            throw new \LogicException('No active transaction to roll back.');
        }

        if ($this->transactionLevel === 1) {
            $this->connection->getPdo()->rollBack();
        } else {
            $this->connection->statement("ROLLBACK TO SAVEPOINT trans_" . ($this->transactionLevel - 1));
        }

        // Work registered inside what was just undone is discarded with it —
        // that is the whole point of waiting for the commit.
        unset($this->committedCallbacks[$this->transactionLevel]);

        $this->transactionLevel--;

        /**
         * Emit point — transaction.rolled_back
         *
         * After the ROLLBACK succeeded. The usual reason to listen: undoing
         * something that was done outside the database and cannot roll back
         * with it — a file written, a job queued.
         * Payload: {@see TransactionEvent}.
         */
        $this->connection->raise(
            DatabaseEvents::TRANSACTION_ROLLED_BACK,
            fn (): TransactionEvent => new TransactionEvent($this->transactionLevel),
        );
    }

    public function transaction(Closure $callback): mixed
    {
        $this->begin();

        try {
            $result = $callback();
            $this->commit();
            return $result;
        } catch (Throwable $exception) {
            // Try-catch the rollback in case the connection itself died —
            // we re-throw the original exception so the caller sees the
            // real cause, not a "no active transaction" red herring.
            try {
                $this->rollBack();
            } catch (Throwable) {
                // Connection-lost-style failures: reset our state to 0
                // so the next request doesn't think it's mid-transaction,
                // and drop work that was waiting on a commit that will
                // never come.
                $this->transactionLevel = 0;
                $this->committedCallbacks = [];
            }
            throw $exception;
        }
    }

    public function level(): int
    {
        return $this->transactionLevel;
    }
}
