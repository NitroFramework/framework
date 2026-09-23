<?php

namespace Tests\Unit\Events;

use Nitro\Container\Container;
use Nitro\Events\CallQueuedListener;
use Nitro\Events\Dispatcher;
use Nitro\Events\InvokeQueuedClosure;
use Nitro\Foundation\Config;
use Nitro\Queue\Contracts\ShouldQueue;
use Nitro\Queue\Drivers\ArrayQueue;
use Nitro\Queue\QueueManager;
use PHPUnit\Framework\TestCase;

/** What reaches the queue when a listener says it should not run now. */
class QueuedListenerParityTest extends TestCase
{
    private Dispatcher $events;
    private ArrayQueue $queue;

    protected function setUp(): void
    {
        Container::setInstance(new Container());
        QueueRecorder::reset();

        $config = Config::fromArray([
            'queue' => [
                'default' => 'array',
                'connections' => [
                    'array' => ['driver' => 'array'],
                    'other' => ['driver' => 'array'],
                ],
            ],
        ]);

        Container::getInstance()->instance(Config::class, $config);

        $manager = new QueueManager(
            config: $config,
            syncQueue: static fn () => throw new \RuntimeException('not used'),
            redis: static fn () => throw new \RuntimeException('not used'),
            batches: static fn () => throw new \RuntimeException('not used'),
            batchCallbacks: static fn () => throw new \RuntimeException('not used'),
        );

        $this->queue = new ArrayQueue();
        $manager->extend('array', $this->queue);
        $manager->extend('other', $this->queue);

        $this->events = new Dispatcher();
        $this->events->setContainer(Container::getInstance());
        $this->events->setQueueResolver(static fn (): QueueManager => $manager);
    }

    protected function tearDown(): void
    {
        Container::setInstance(new Container());
    }

    /** The job that was queued, decoded back to an object. */
    private function queued(string $queue = 'default'): CallQueuedListener|InvokeQueuedClosure
    {
        $envelope = $this->queue->pop($queue);

        $this->assertNotNull($envelope, 'nothing was queued on [' . $queue . ']');

        return $envelope->decode()['instance'];
    }

    // ── Being queued at all ───────────────────────────────────────────

    public function test_a_queued_listener_is_pushed_rather_than_called(): void
    {
        $this->events->listen('e', ParityQueuedListener::class);

        $this->events->dispatch('e', ['payload']);

        $this->assertSame([], QueueRecorder::$ran, 'the listener must not run now');
        $this->assertSame(ParityQueuedListener::class, $this->queued()->listenerClass);
    }

    /** A queued listener cannot veto anything. */
    public function test_a_queued_listener_does_not_halt(): void
    {
        $this->events->listen('e', ParityQueuedListener::class);
        $this->events->listen('e', static fn (): string => 'reached');

        $this->assertSame('reached', $this->events->until('e'));
    }

    /** A listener can decline the events it has nothing to do with. */
    public function test_a_listener_can_refuse_to_be_queued(): void
    {
        $this->events->listen('e', DecliningListener::class);

        $this->events->dispatch('e', ['skip']);

        $this->assertSame(0, $this->queue->size(), 'shouldQueue() said no');
    }

    public function test_a_listener_that_accepts_is_queued(): void
    {
        $this->events->listen('e', DecliningListener::class);

        $this->events->dispatch('e', ['take']);

        $this->assertSame(1, $this->queue->size());
    }

    // ── Carrying the listener's terms ─────────────────────────────────

    /** The listener's own terms travel with the job. */
    public function test_the_listeners_terms_reach_the_job(): void
    {
        $this->events->listen('e', ConfiguredListener::class);

        $this->events->dispatch('e', ['payload']);

        $job = $this->queued('reports');

        $this->assertSame(5, $job->tries());
        $this->assertSame(30, $job->timeout());
        $this->assertSame(120, $job->backoff());
        $this->assertSame('other', $job->connectionName());
    }

    public function test_a_listener_can_route_by_the_event(): void
    {
        $this->events->listen('e', RoutingListener::class);

        $this->events->dispatch('e', ['urgent']);

        $this->assertSame(1, $this->queue->size('urgent'), 'viaQueue() saw the event');
    }

    public function test_a_listener_can_ask_for_a_delay(): void
    {
        $this->events->listen('e', DelayedListener::class);

        $this->events->dispatch('e', ['payload']);

        $this->assertNull($this->queue->pop('default'), 'a delayed job is not yet eligible');
        $this->assertSame(1, $this->queue->size('default'));
    }

    public function test_a_listeners_middleware_reaches_the_job(): void
    {
        $this->events->listen('e', MiddlewareListener::class);

        $this->events->dispatch('e', ['payload']);

        $this->assertSame(['throttle'], $this->queued()->middleware);
    }

    // ── Failing ───────────────────────────────────────────────────────

    /** A failed queued listener is told so. */
    public function test_a_failed_job_reaches_the_listeners_failed_hook(): void
    {
        Container::getInstance()->instance(FailingListener::class, new FailingListener());

        $job = new CallQueuedListener(FailingListener::class, 'handle', 'payload');

        $job->failed(new \RuntimeException('it broke'));

        $this->assertSame(['failed: it broke'], QueueRecorder::$ran);
    }

    /** The failed store names the listener, not the job that wrapped it. */
    public function test_the_job_is_named_after_the_listener(): void
    {
        $job = new CallQueuedListener(ParityQueuedListener::class, 'handle', null);

        $this->assertSame(ParityQueuedListener::class, $job->displayName());
    }

    // ── Queued closures ──────────────────────────────────────────────

    /** A closure listener can run on the queue too. */
    public function test_a_queueable_closure_is_registered_by_its_hint(): void
    {
        $this->events->listen(queueable(static function (ParityQueuedEvent $event): void {
            QueueRecorder::$ran[] = 'ran';
        }));

        $this->events->dispatch(new ParityQueuedEvent());

        $this->assertSame([], QueueRecorder::$ran, 'the body waits for the worker');
        $this->assertInstanceOf(InvokeQueuedClosure::class, $this->queued());
    }

    public function test_a_queued_closure_runs_when_the_worker_gets_to_it(): void
    {
        $this->events->listen(queueable(static function (ParityQueuedEvent $event): void {
            QueueRecorder::$ran[] = 'ran';
        }));

        $this->events->dispatch(new ParityQueuedEvent());

        $this->queued()->handle();

        $this->assertSame(['ran'], QueueRecorder::$ran);
    }

    public function test_a_queued_closure_can_pick_its_queue(): void
    {
        $this->events->listen(queueable(static function (ParityQueuedEvent $event): void {
            //
        })->onQueue('slow'));

        $this->events->dispatch(new ParityQueuedEvent());

        $this->assertSame(1, $this->queue->size('slow'));
    }

    public function test_a_failed_queued_closure_runs_its_catch(): void
    {
        $this->events->listen(queueable(static function (ParityQueuedEvent $event): void {
            throw new \RuntimeException('no');
        })->catch(static function (ParityQueuedEvent $event, \Throwable $exception): void {
            QueueRecorder::$ran[] = 'caught: ' . $exception->getMessage();
        }));

        $this->events->dispatch(new ParityQueuedEvent());

        $this->queued()->failed(new \RuntimeException('no'));

        $this->assertSame(['caught: no'], QueueRecorder::$ran);
    }

    // ── Without an application ────────────────────────────────────────

    /** With no queue to push to, the listener runs in process. */
    public function test_a_queued_listener_runs_inline_when_there_is_no_queue(): void
    {
        $bare = new Dispatcher();
        $bare->listen('e', ParityQueuedListener::class);

        $bare->dispatch('e', ['payload']);

        $this->assertSame(['payload'], QueueRecorder::$ran);
    }
}

// ── Events ────────────────────────────────────────────────────────────

class ParityQueuedEvent {}

// ── Listeners ─────────────────────────────────────────────────────────

class ParityQueuedListener implements ShouldQueue
{
    public function handle(mixed $payload): void
    {
        QueueRecorder::$ran[] = $payload;
    }
}

class DecliningListener implements ShouldQueue
{
    public function shouldQueue(mixed $payload): bool
    {
        return $payload !== 'skip';
    }

    public function handle(mixed $payload): void {}
}

class ConfiguredListener implements ShouldQueue
{
    public int $tries = 5;

    public int $timeout = 30;

    public int $backoff = 120;

    public string $queue = 'reports';

    public string $connection = 'other';

    public function handle(mixed $payload): void {}
}

class RoutingListener implements ShouldQueue
{
    public function viaQueue(mixed $payload): string
    {
        return (string) $payload;
    }

    public function handle(mixed $payload): void {}
}

class DelayedListener implements ShouldQueue
{
    public function withDelay(mixed $payload): int
    {
        return 600;
    }

    public function handle(mixed $payload): void {}
}

class MiddlewareListener implements ShouldQueue
{
    public function middleware(mixed $payload): array
    {
        return ['throttle'];
    }

    public function handle(mixed $payload): void {}
}

class FailingListener implements ShouldQueue
{
    public function handle(mixed $payload): void
    {
        throw new \RuntimeException('it broke');
    }

    public function failed(mixed $payload, \Throwable $exception): void
    {
        QueueRecorder::$ran[] = 'failed: ' . $exception->getMessage();
    }
}

// ── Doubles ───────────────────────────────────────────────────────────

class QueueRecorder
{
    /** @var array<int, mixed> */
    public static array $ran = [];

    public static function reset(): void
    {
        self::$ran = [];
    }
}
