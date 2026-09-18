<?php

namespace Nitro\Facades;

/**
 * Bus facade — dispatches jobs and batches them.
 *
 *   Bus::dispatch(new SendInvoice($order->id));
 *   Bus::batch([new ImportRows(1)])->dispatch();
 *
 * @method static int|string dispatch(\Nitro\Queue\Job $job)
 * @method static \Nitro\Queue\Batching\PendingBatch batch(array|\Nitro\Queue\Job $jobs = [])
 */
class Bus extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'queue';
    }
}
