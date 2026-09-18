<?php

namespace Nitro\Facades;

/**
 * Queue facade — pushes jobs and starts batches.
 *
 *   Queue::push(new SendInvoice($order->id));
 *   Queue::batch([new ImportRows(1)])->dispatch();
 *
 * @method static \Nitro\Queue\Contracts\Queue connection(?string $name = null)
 * @method static int|string push(\Nitro\Queue\Job $job, ?string $queue = null, ?string $connection = null)
 * @method static \Nitro\Queue\Batching\PendingBatch batch(array|\Nitro\Queue\Job $jobs = [])
 * @method static void extend(string $name, \Nitro\Queue\Contracts\Queue $queue)
 */
class Queue extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'queue';
    }
}
