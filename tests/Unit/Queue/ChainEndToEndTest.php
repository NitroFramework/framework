<?php

namespace Tests\Unit\Queue;

use Nitro\Container\Container;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Foundation\Config;
use Nitro\Queue\Batching\BatchCallbacks;
use Nitro\Queue\Batching\BatchRepository;
use Nitro\Queue\Drivers\ArrayQueue;
use Nitro\Queue\Job;
use Nitro\Queue\QueueManager;
use Nitro\Queue\Worker;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A chain driven by a real worker, not by calling its hook by hand.
 *
 * The rest of the chain tests stand in for the worker, which proves the chain
 * assembles but not that anything ever advances it. These run the worker loop
 * against a real queue so the whole path is exercised: pop, handle, delete,
 * queue the next one, pop that.
 */
class ChainEndToEndTest extends TestCase
{
    private Container $container;

    private QueueManager $queues;

    private ArrayQueue $array;

    private Worker $worker;

    protected function setUp(): void
    {
        parent::setUp();

        Container::setInstance(new Container());

        $this->container = Container::getInstance();

        ChainTrace::reset();

        $config = Config::fromArray([
            'queue' => [
                'default' => 'array',
                'connections' => ['array' => ['driver' => 'array']],
            ],
        ]);

        $this->container->instance(Config::class, $config);

        $unused = static fn (): never => throw new RuntimeException('not reached in this test');

        $this->queues = new QueueManager(
            $config,
            $unused,
            $unused,
            static fn (): BatchRepository => $this->container->resolve(BatchRepository::class),
            static fn (): BatchCallbacks => new BatchCallbacks($this->container->resolve(ClassResolver::class)),
        );

        $this->array = new ArrayQueue();
        $this->queues->extend('array', $this->array);

        $this->container->instance('queue', $this->queues);
        $this->container->instance(QueueManager::class, $this->queues);

        $this->worker = new Worker(
            $this->queues,
            new InMemoryFailedJobStore(),
            $this->container->resolve(ClassResolver::class),
            null,
        );
    }

    protected function tearDown(): void
    {
        Container::setInstance(new Container());

        parent::tearDown();
    }

    /** Run the worker until the queue is empty, with a cap so a bug cannot hang the suite. */
    private function drain(int $limit = 20): int
    {
        $ran = 0;

        while ($this->array->size('default') > 0 && $ran < $limit) {
            $this->worker->run([
                'connection' => 'array',
                'queue' => 'default',
                'once' => true,
                'sleep' => 0,
                'tries' => 1,
            ]);

            $ran++;
        }

        return $ran;
    }

    // ─── The whole chain, actually run ────────────────────

    public function test_a_worker_runs_a_chain_in_order(): void
    {
        $this->queues->chain([
            new TracedStep('first'),
            new TracedStep('second'),
            new TracedStep('third'),
        ])->dispatch();

        $this->assertSame(1, $this->array->size('default'), 'only the first job is queued to begin with');

        $this->drain();

        $this->assertSame(['first', 'second', 'third'], ChainTrace::$ran);
    }

    /**
     * The point of the whole thing: the second job does not exist on any queue
     * until the first has succeeded.
     */
    public function test_the_next_job_appears_only_after_the_one_before_it_ran(): void
    {
        $this->queues->chain([new TracedStep('first'), new TracedStep('second')])->dispatch();

        $this->assertSame(1, $this->array->size('default'));

        // One turn of the worker.
        $this->worker->run(['connection' => 'array', 'queue' => 'default', 'once' => true, 'sleep' => 0]);

        $this->assertSame(['first'], ChainTrace::$ran);
        $this->assertSame(1, $this->array->size('default'), 'the second is queued now, and only now');

        $this->worker->run(['connection' => 'array', 'queue' => 'default', 'once' => true, 'sleep' => 0]);

        $this->assertSame(['first', 'second'], ChainTrace::$ran);
        $this->assertSame(0, $this->array->size('default'));
    }

    /** A failure takes the rest of the chain with it. */
    public function test_a_failing_job_stops_the_chain(): void
    {
        $this->queues->chain([
            new TracedStep('first'),
            new FailingStep(),
            new TracedStep('never'),
        ])->dispatch();

        $this->drain();

        $this->assertSame(['first'], ChainTrace::$ran, 'the job after the failure never ran');
        $this->assertSame(0, $this->array->size('default'), 'and it was never queued either');
    }

    public function test_a_chain_catch_callback_runs_when_the_chain_breaks(): void
    {
        $this->queues->chain([new FailingStep(), new TracedStep('never')])
            ->catch(ChainTrace::class)
            ->dispatch();

        $this->drain();

        $this->assertSame(['boom'], ChainTrace::$caught);
        $this->assertSame([], ChainTrace::$ran);
    }

    /** A chain of one behaves like a plain dispatch. */
    public function test_a_chain_of_one_runs_and_queues_nothing_further(): void
    {
        $this->queues->chain([new TracedStep('alone')])->dispatch();

        $this->drain();

        $this->assertSame(['alone'], ChainTrace::$ran);
        $this->assertSame(0, $this->array->size('default'));
    }

    /** A job that releases itself has not succeeded, so the chain must not advance. */
    public function test_a_released_job_does_not_advance_the_chain(): void
    {
        $this->queues->chain([new ReleasingStep(), new TracedStep('never')])->dispatch();

        $this->worker->run(['connection' => 'array', 'queue' => 'default', 'once' => true, 'sleep' => 0]);

        $this->assertSame([], ChainTrace::$ran);
        $this->assertSame(
            1,
            $this->array->size('default'),
            'the released job is back on the queue, and the next one was not added'
        );
    }

    /** The chain survives being serialized into the payload and read back. */
    public function test_the_remaining_chain_survives_the_round_trip(): void
    {
        $this->queues->chain([
            new TracedStep('first'),
            new CarriesState('kept', [1, 2, 3]),
        ])->dispatch();

        $this->drain();

        $this->assertSame(['first', 'kept:1,2,3'], ChainTrace::$ran);
    }
}

class TracedStep extends Job
{
    public function __construct(public string $tag = '')
    {
    }

    public function handle(): void
    {
        ChainTrace::$ran[] = $this->tag;
    }
}

class CarriesState extends Job
{
    /** @param array<int, int> $numbers */
    public function __construct(
        public string $tag = '',
        public array $numbers = [],
    ) {
    }

    public function handle(): void
    {
        ChainTrace::$ran[] = $this->tag . ':' . implode(',', $this->numbers);
    }
}

class FailingStep extends Job
{
    protected int $tries = 1;

    public function handle(): void
    {
        throw new RuntimeException('boom');
    }
}

class ReleasingStep extends Job
{
    use \Nitro\Queue\InteractsWithQueue;

    public function handle(): void
    {
        $this->release(0);
    }
}

/** Where the jobs and the catch callback leave their marks. */
class ChainTrace
{
    /** @var array<int, string> */
    public static array $ran = [];

    /** @var array<int, string> */
    public static array $caught = [];

    public static function reset(): void
    {
        self::$ran = [];
        self::$caught = [];
    }

    public function __invoke(\Throwable $exception): void
    {
        self::$caught[] = $exception->getMessage();
    }
}
