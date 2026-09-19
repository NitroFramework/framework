<?php

namespace Nitro\Database\Events;

/**
 * Events the database layer raises.
 *
 * The query pair is the hottest in the framework — a page doing forty queries
 * raises eighty — so both are emitted through eventLazy() and build nothing
 * unless something is listening.
 */
class DatabaseEvents
{
    /** Before a statement runs. Carries a {@see QueryEvent} with no elapsed time yet. */
    const QUERY_EXECUTING = 'query.executing';

    /** After it ran and the result was read. Carries a {@see QueryEvent} with the time. */
    const QUERY_EXECUTED = 'query.executed';

    /** A BEGIN or SAVEPOINT succeeded. Carries a {@see TransactionEvent}. */
    const TRANSACTION_BEGINNING = 'transaction.beginning';

    /** A COMMIT or RELEASE SAVEPOINT succeeded. Carries a {@see TransactionEvent}. */
    const TRANSACTION_COMMITTED = 'transaction.committed';

    /** A ROLLBACK succeeded. Carries a {@see TransactionEvent}. */
    const TRANSACTION_ROLLED_BACK = 'transaction.rolled_back';
}
