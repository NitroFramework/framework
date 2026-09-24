<?php

namespace Nitro\Facades;

use Nitro\Queue\QueueFake;

/**
 * Bus facade — dispatches jobs, batches them and chains them.
 *
 *   Bus::dispatch(new SendInvoice($order->id));
 *   Bus::batch([new ImportRows(1)])->dispatch();
 *   Bus::chain([new PullOrders(), new Reconcile()])->dispatch();
 *
 * The same manager as {@see Queue}. Laravel splits dispatching from storing
 * into two layers; here they are one, and this is the name for the dispatching
 * half of it.
 *
 * @method static int|string dispatch(\Nitro\Queue\Job $job, ?string $queue = null, ?string $connection = null, int $delay = 0)
 * @method static void dispatchAfterResponse(\Nitro\Queue\Job $job, ?string $queue = null, ?string $connection = null)
 * @method static array bulk(array $jobs, ?string $queue = null, ?string $connection = null)
 * @method static \Nitro\Queue\Batching\PendingBatch batch(array|\Nitro\Queue\Job $jobs = [])
 * @method static \Nitro\Queue\PendingChain chain(array $jobs)
 * @method static \Nitro\Queue\Batching\Batch|null findBatch(string $batchId)
 *
 * @see \Nitro\Queue\QueueManager
 */
class Bus extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'queue';
    }

    /**
     * Record what is dispatched instead of queueing it.
     *
     * @param class-string|array<int, class-string> $except Jobs to still queue for real.
     */
    public static function fake(string|array $except = []): QueueFake
    {
        return Queue::fake($except);
    }
}
