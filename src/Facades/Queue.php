<?php

namespace Nitro\Facades;

use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Queue\QueueFake;
use Nitro\Queue\QueueManager;

/**
 * Queue facade — pushes jobs, batches them and chains them.
 *
 *   Queue::push(new SendInvoice($order->id));
 *   Queue::batch([new ImportRows(1)])->dispatch();
 *   Queue::chain([new PullOrders(), new Reconcile()])->dispatch();
 *
 * @method static \Nitro\Queue\Contracts\Queue connection(?string $name = null)
 * @method static int|string push(\Nitro\Queue\Job $job, ?string $queue = null, ?string $connection = null)
 * @method static int|string later(int $delay, \Nitro\Queue\Job $job, ?string $queue = null, ?string $connection = null)
 * @method static int|string dispatch(\Nitro\Queue\Job $job, ?string $queue = null, ?string $connection = null, int $delay = 0)
 * @method static void dispatchAfterResponse(\Nitro\Queue\Job $job, ?string $queue = null, ?string $connection = null)
 * @method static array bulk(array $jobs, ?string $queue = null, ?string $connection = null)
 * @method static \Nitro\Queue\Batching\PendingBatch batch(array|\Nitro\Queue\Job $jobs = [])
 * @method static \Nitro\Queue\PendingChain chain(array $jobs)
 * @method static \Nitro\Queue\Batching\Batch|null findBatch(string $batchId)
 * @method static void extend(string $name, \Nitro\Queue\Contracts\Queue $queue)
 * @method static void raise(object $event)
 *
 * @see \Nitro\Queue\QueueManager
 */
class Queue extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'queue';
    }

    /**
     * Record what is dispatched instead of queueing it.
     *
     * Returns the fake, so the assertions can be reached either through it or
     * through this facade — Queue::assertPushed(...) forwards like any other
     * call, because the fake is now what the binding resolves to.
     *
     * @param class-string|array<int, class-string> $except Jobs to still queue for real.
     */
    public static function fake(string|array $except = []): QueueFake
    {
        $container = app();

        $real = $container->has('queue') ? $container->resolve('queue') : null;

        $fake = new QueueFake(
            $container->resolve(ConfigRepository::class),
            $real instanceof QueueManager ? $real : null,
        );

        if ($except !== []) {
            $fake->except($except);
        }

        $container->instance('queue', $fake);
        $container->instance(QueueManager::class, $fake);

        return $fake;
    }
}
