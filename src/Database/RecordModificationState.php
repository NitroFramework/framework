<?php

namespace Nitro\Database;

use Nitro\Foundation\Contracts\ResetsBetweenRequests;

/**
 * Clears the connection's "has written" note between worker requests.
 *
 * With 'sticky' reads on, a connection that has written sends its reads to
 * the write server. The connection outlives the request in a worker, so
 * without this the first request to write would send every later request's
 * reads there too.
 */
class RecordModificationState implements ResetsBetweenRequests
{
    public function resetBetweenRequests(): void
    {
        DB::forgetRecordModificationState();
    }
}
