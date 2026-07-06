<?php

namespace Nitro\Database\Query;

use Nitro\Database\Connection;
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

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
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
        $this->transactionLevel--;
    }

    public function transaction(Closure $callback): mixed
    {
        $this->begin();

        try {
            $result = $callback();
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            // Try-catch the rollback in case the connection itself died —
            // we re-throw the original exception so the caller sees the
            // real cause, not a "no active transaction" red herring.
            try {
                $this->rollBack();
            } catch (Throwable) {
                // Connection-lost-style failures: reset our state to 0
                // so the next request doesn't think it's mid-transaction.
                $this->transactionLevel = 0;
            }
            throw $e;
        }
    }

    public function level(): int
    {
        return $this->transactionLevel;
    }
}
