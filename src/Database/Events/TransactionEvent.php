<?php

namespace Nitro\Database\Events;

/**
 * Payload for transaction.beginning, transaction.committed and
 * transaction.rolled_back.
 *
 * The level is the whole story. Transactions nest through SAVEPOINTs, so a
 * commit at level 1 or above released an inner savepoint and the outer
 * transaction is still open — the data is not durable yet. Only level 0 on
 * a commit means the write actually landed.
 */
class TransactionEvent
{
    /**
     * @param int $level Nesting depth after the operation; 0 means no transaction is open.
     */
    public function __construct(
        public readonly int $level,
    ) {}
}
