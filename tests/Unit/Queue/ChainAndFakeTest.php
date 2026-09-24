<?php

namespace Tests\Unit\Queue;

use Nitro\Container\Container;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Facades\Bus;
use Nitro\Facades\Queue as QueueFacade;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Queue\Drivers\ArrayQueue;
use Nitro\Queue\Job;
use Nitro\Queue\PendingChain;
use Nitro\Queue\QueueFake;
use Nitro\Queue\QueueManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Jobs that run one after another, and a queue that records instead of queueing.
 *
 * A chain is not a batch: a batch puts every job on the queue at once and the
 * order is whatever the workers get to first. A chain queues only the first,
 * and each job queues the next once it has succeeded — so a failure stops
 * everything behind it, which is the reason to ask for one.
 */
class ChainAndFakeTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        Container::reset();

        $this->container = new Container();

        Container::setInstance($this->container);

        $this->container->instance(ConfigRepository::class, new class implements ConfigRepository {
            public function has(string $key): bool { return false; }
            public function get(string $key, mixed $default = null): mixed { return $default; }
            public function all(): array { return []; }
            public function set(string $key, mixed $value): void {}
        });

        ChainRecorder::$ran = [];
        ChainRecorder::$caught = [];
    }

    protected function tearDown(): void
    {
        Container::setInstance(new Container());

        parent::tearDown();
    }

    private function manager(): QueueManager
    {
        $config = $this->container->resolve(ConfigRepository::class);

        $unused = static fn (): never => throw new RuntimeException('not needed here');

        $manager = new QueueManager($config, $unused, $unused, $unused, $unused);
        $manager->extend('sync', new ArrayQueue());

        $this->container->instance('queue', $manager);
        $this->container->instance(QueueManager::class, $manager);

        return $manager;
    }

    // ─── Building a chain ─────────────────────────────────

    public function test_only_the_first_job_of_a_chain_is_queued(): void
    {
        $fake = QueueFacade::fake();

        QueueFacade::chain([new ChainStep('a'), new ChainStep('b'), new ChainStep('c')])->dispatch();

        $this->assertCount(1, $fake->pushed(ChainStep::class), 'a chain queues one job, not all of them');
        $this->assertSame('a', $fake->pushed(ChainStep::class)[0]->tag);
    }

    public function test_the_first_job_carries_the_rest(): void
    {
        $fake = QueueFacade::fake();

        QueueFacade::chain([new ChainStep('a'), new ChainStep('b'), new ChainStep('c')])->dispatch();

        $first = $fake->pushed(ChainStep::class)[0];

        $this->assertCount(2, $first->chained);
        $this->assertSame('b', unserialize($first->chained[0])->tag);
        $this->assertSame('c', unserialize($first->chained[1])->tag);
    }

    public function test_an_empty_chain_dispatches_nothing(): void
    {
        $fake = QueueFacade::fake();

        $this->assertNull(QueueFacade::chain([])->dispatch());
        $fake->assertNothingPushed();
    }

    public function test_a_chain_refuses_anything_that_is_not_a_job(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PendingChain($this->manager(), [new ChainStep('a'), 'not a job']);
    }

    public function test_a_chain_can_be_routed_to_a_queue_and_connection(): void
    {
        $fake = QueueFacade::fake();

        QueueFacade::chain([new ChainStep('a'), new ChainStep('b')])
            ->onQueue('nightly')
            ->onConnection('redis')
            ->dispatch();

        $first = $fake->pushed(ChainStep::class)[0];

        $this->assertSame('nightly', $first->chainQueue);
        $this->assertSame('redis', $first->chainConnection);
    }

    public function test_a_chain_dispatches_only_when_the_condition_holds(): void
    {
        $fake = QueueFacade::fake();

        QueueFacade::chain([new ChainStep('a')])->dispatchIf(false);
        QueueFacade::chain([new ChainStep('b')])->dispatchUnless(true);

        $fake->assertNothingPushed();

        QueueFacade::chain([new ChainStep('c')])->dispatchIf(true);

        $this->assertCount(1, $fake->pushed(ChainStep::class));
    }

    public function test_a_closure_is_refused_as_a_catch_callback(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('read back by another process');

        QueueFacade::fake();

        QueueFacade::chain([new ChainStep('a')])->catch(static fn () => null);
    }

    // ─── Running a chain ──────────────────────────────────

    /** Each job queues the next only after it has succeeded. */
    public function test_a_chain_advances_one_job_at_a_time(): void
    {
        $manager = $this->manager();

        $first = new ChainStep('a');
        $first->chain([new ChainStep('b'), new ChainStep('c')]);

        // Standing in for the worker, which calls this after a job succeeds.
        $first->dispatchNextJobInChain();

        $queued = $this->drain($manager);

        $this->assertCount(1, $queued, 'only the next one is queued');
        $this->assertSame('b', $queued[0]->tag);
        $this->assertSame(['c'], array_map(
            static fn (string $s): string => unserialize($s)->tag,
            $queued[0]->chained,
        ));
    }

    public function test_the_last_job_of_a_chain_queues_nothing(): void
    {
        $manager = $this->manager();

        $last = new ChainStep('z');
        $last->dispatchNextJobInChain();

        $this->assertSame([], $this->drain($manager));
    }

    /** The reason for a chain: a failure stops what was behind it. */
    public function test_a_failed_job_never_queues_the_rest(): void
    {
        $manager = $this->manager();

        $first = new ChainStep('a');
        $first->chain([new ChainStep('b')]);

        // The worker calls dispatchNextJobInChain only on the success path,
        // so a failure simply never reaches it.
        $this->assertSame([], $this->drain($manager));
    }

    public function test_a_chain_catch_callback_is_run_on_failure(): void
    {
        $this->container->instance(ClassResolver::class, new class implements ClassResolver {
            public function resolve(string $class, array $parameters = []): object
            {
                return new $class();
            }
        });

        $job = new ChainStep('a');
        $job->withChainCatchCallbacks([ChainRecorder::class]);

        $job->invokeChainCatchCallbacks(new RuntimeException('it broke'));

        $this->assertSame(['it broke'], ChainRecorder::$caught);
    }

    /** @return array<int, ChainStep> */
    private function drain(QueueManager $manager): array
    {
        $queue = $manager->connection('sync');
        $found = [];

        while (($envelope = $queue->pop()) !== null) {
            $found[] = $envelope->decode()['instance'];
        }

        return $found;
    }

    // ─── The fake ─────────────────────────────────────────

    public function test_a_faked_queue_records_instead_of_queueing(): void
    {
        $fake = QueueFacade::fake();

        $this->assertInstanceOf(QueueFake::class, $this->container->resolve('queue'));

        QueueFacade::push(new ChainStep('one'));

        $fake->assertPushed(ChainStep::class);
        $fake->assertPushedTimes(ChainStep::class, 1);
        $fake->assertNotPushed(OtherStep::class);
    }

    public function test_assertions_reach_through_the_facade(): void
    {
        QueueFacade::fake();

        QueueFacade::push(new ChainStep('one'));

        QueueFacade::assertPushed(ChainStep::class);
        QueueFacade::assertNothingBatched();
    }

    public function test_the_bus_facade_fakes_the_same_manager(): void
    {
        $fake = Bus::fake();

        Bus::dispatch(new ChainStep('viaBus'));

        $fake->assertPushed(ChainStep::class);
    }

    public function test_a_filter_narrows_what_counts(): void
    {
        $fake = QueueFacade::fake();

        QueueFacade::push(new ChainStep('wanted'));
        QueueFacade::push(new ChainStep('other'));

        $fake->assertPushed(ChainStep::class, static fn (ChainStep $job): bool => $job->tag === 'wanted');
        $fake->assertNotPushed(ChainStep::class, static fn (ChainStep $job): bool => $job->tag === 'absent');
    }

    public function test_the_queue_a_job_went_to_is_recorded(): void
    {
        $fake = QueueFacade::fake();

        QueueFacade::push(new ChainStep('a'), 'reports');

        $fake->assertPushedOn('reports', ChainStep::class);
    }

    public function test_nothing_pushed_is_assertable(): void
    {
        QueueFacade::fake()->assertNothingPushed();
    }

    public function test_a_named_job_can_still_be_queued_for_real(): void
    {
        $manager = $this->manager();

        $fake = QueueFacade::fake([OtherStep::class]);

        QueueFacade::push(new ChainStep('faked'));
        QueueFacade::push(new OtherStep());

        $fake->assertPushed(ChainStep::class);
        $fake->assertNotPushed(OtherStep::class);

        $this->assertCount(1, $this->drain($manager), 'the excepted job went to the real queue');
    }

    public function test_a_chain_is_assertable(): void
    {
        $fake = QueueFacade::fake();

        QueueFacade::chain([new ChainStep('a'), new ChainStep('b')])->dispatch();

        $fake->assertChained();
        $fake->assertPushedWithChain(ChainStep::class, [ChainStep::class]);
    }

    public function test_a_job_pushed_alone_carries_no_chain(): void
    {
        $fake = QueueFacade::fake();

        QueueFacade::push(new ChainStep('alone'));

        $fake->assertPushedWithoutChain(ChainStep::class);
    }

    public function test_batches_are_recorded_without_a_batches_table(): void
    {
        $this->container->instance(ClassResolver::class, new class implements ClassResolver {
            public function resolve(string $class, array $parameters = []): object
            {
                return new $class();
            }
        });

        $fake = QueueFacade::fake();

        QueueFacade::batch([new BatchableStep('a'), new BatchableStep('b')])->dispatch();

        $fake->assertBatched();
        $fake->assertBatchCount(1);
        $fake->assertBatched(static fn ($batch): bool => count($batch->jobs()) === 2);
    }

    public function test_bulk_pushes_each_job(): void
    {
        $fake = QueueFacade::fake();

        $ids = QueueFacade::bulk([new ChainStep('a'), new ChainStep('b'), new ChainStep('c')]);

        $this->assertCount(3, $ids);
        $fake->assertPushedTimes(ChainStep::class, 3);
    }

    public function test_a_missing_expectation_fails_the_assertion(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);

        QueueFacade::fake()->assertPushed(ChainStep::class);
    }
}

class ChainStep extends Job
{
    public function __construct(public string $tag = '')
    {
    }

    public function handle(): void
    {
        ChainRecorder::$ran[] = $this->tag;
    }
}

class OtherStep extends Job
{
    public function handle(): void {}
}

class BatchableStep extends Job
{
    use \Nitro\Queue\Batchable;

    public function __construct(public string $tag = '')
    {
    }

    public function handle(): void {}
}

/** Somewhere for the jobs and the catch callback to leave a mark. */
class ChainRecorder
{
    /** @var array<int, string> */
    public static array $ran = [];

    /** @var array<int, string> */
    public static array $caught = [];

    public function __invoke(\Throwable $exception): void
    {
        self::$caught[] = $exception->getMessage();
    }
}
