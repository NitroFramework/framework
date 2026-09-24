<?php

namespace Tests\Unit\Queue;

use Nitro\Cache\CacheManager;
use Nitro\Console\Commands\QueueCommands;
use Nitro\Console\ExitCode;
use Nitro\Console\OutputFormatter;
use Nitro\Container\Container;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Queue\Drivers\ArrayQueue;
use Nitro\Queue\Job;
use Nitro\Queue\QueuedJob;
use Nitro\Queue\QueueManager;
use Nitro\Queue\Worker;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Holding workers back, seeing how much is waiting, and emptying a queue.
 *
 * Pausing is not stopping: the process and anything it has already reserved
 * are kept, and only new work stops. That is what makes it usable during a
 * migration, where stopping workers would drop their reservations.
 */
class QueueControlCommandsTest extends TestCase
{
    private Container $container;

    private QueueManager $queues;

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

        $unused = static fn (): never => throw new RuntimeException('not needed here');

        $this->queues = new QueueManager(
            $this->container->resolve(ConfigRepository::class),
            $unused,
            $unused,
            $unused,
            $unused,
        );

        $this->queues->extend('sync', new ArrayQueue());

        $this->container->instance('queue', $this->queues);
        $this->container->instance(QueueManager::class, $this->queues);
    }

    protected function tearDown(): void
    {
        Container::setInstance(new Container());

        parent::tearDown();
    }

    private function command(): QueueCommands
    {
        return new QueueCommands($this->container, new OutputFormatter(decorated: false));
    }

    /** @param array<int, string> $arguments */
    private function execute(string $signature, array $arguments = []): int
    {
        ob_start();

        try {
            return $this->command()->handle($signature, $arguments);
        } finally {
            $this->printed = (string) ob_get_clean();
        }
    }

    private string $printed = '';

    private function withCache(): CacheManager
    {
        $cache = new CacheManager(['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]]);

        $this->container->instance(CacheManager::class, $cache);

        return $cache;
    }

    private function fill(string $queue, int $count): void
    {
        $driver = $this->queues->connection('sync');

        for ($i = 0; $i < $count; $i++) {
            $driver->push(new QueuedJob(
                id: null,
                queue: $queue,
                payload: QueuedJob::encode(new ControlJob()),
                attempts: 0,
                availableAt: time(),
                reservedAt: null,
                createdAt: time(),
            ), $queue);
        }
    }

    // ─── pause / resume ───────────────────────────────────

    public function test_pausing_needs_the_cache_because_that_is_what_a_worker_reads(): void
    {
        $this->assertSame(ExitCode::FAILURE, $this->execute('queue:pause'));
        $this->assertStringContainsString('cache', $this->printed);
    }

    public function test_pausing_sets_the_flag_a_worker_checks(): void
    {
        $cache = $this->withCache();

        $this->assertSame(ExitCode::SUCCESS, $this->execute('queue:pause'));
        $this->assertNotNull($cache->get('queue:paused'));
    }

    public function test_resuming_clears_it(): void
    {
        $cache = $this->withCache();

        $this->execute('queue:pause');
        $this->assertSame(ExitCode::SUCCESS, $this->execute('queue:resume'));

        $this->assertNull($cache->get('queue:paused'));
    }

    public function test_one_connection_can_be_paused_on_its_own(): void
    {
        $cache = $this->withCache();

        $this->execute('queue:pause', ['--connection=redis']);

        $this->assertNotNull($cache->get('queue:paused:redis'));
        $this->assertNull($cache->get('queue:paused'), 'other connections keep working');
    }

    public function test_one_queue_can_be_paused_on_its_own(): void
    {
        $cache = $this->withCache();

        $this->execute('queue:pause', ['--connection=redis', '--queue=reports']);

        $this->assertNotNull($cache->get('queue:paused:redis:reports'));
        $this->assertNull($cache->get('queue:paused:redis'));
    }

    /** A queue belongs to a connection, so naming one without the other is nothing. */
    public function test_naming_a_queue_without_a_connection_is_refused(): void
    {
        $this->withCache();

        $this->assertSame(ExitCode::INVALID, $this->execute('queue:pause', ['--queue=reports']));
    }

    /**
     * A blanket pause must not keep holding a queue that was resumed by name,
     * which is what clearing only the narrowest key would do.
     */
    public function test_resuming_by_name_clears_the_wider_pauses_too(): void
    {
        $cache = $this->withCache();

        $this->execute('queue:pause');
        $this->execute('queue:resume', ['--connection=redis', '--queue=reports']);

        $this->assertNull($cache->get('queue:paused'));
    }

    /** The keys are the contract between the command and the worker. */
    public function test_the_worker_looks_for_the_keys_the_command_writes(): void
    {
        $this->assertSame(['queue:paused'], Worker::pauseKeys(null));

        $this->assertSame(
            ['queue:paused', 'queue:paused:redis'],
            Worker::pauseKeys('redis'),
        );

        $this->assertSame(
            ['queue:paused', 'queue:paused:redis', 'queue:paused:redis:reports'],
            Worker::pauseKeys('redis', 'reports'),
        );
    }

    public function test_a_worker_watching_several_queues_is_paused_by_any_of_them(): void
    {
        $this->assertSame(
            ['queue:paused', 'queue:paused:redis', 'queue:paused:redis:high', 'queue:paused:redis:low'],
            Worker::pauseKeys('redis', 'high,low'),
        );
    }

    // ─── monitor ──────────────────────────────────────────

    public function test_monitor_reports_what_is_waiting(): void
    {
        $this->fill('default', 3);

        $this->assertSame(ExitCode::SUCCESS, $this->execute('queue:monitor'));
        $this->assertStringContainsString('3', $this->printed);
    }

    /** Non-zero, so a scheduler or a monitor can act on it. */
    public function test_monitor_fails_when_a_queue_is_over_the_limit(): void
    {
        $this->fill('default', 5);

        $this->assertSame(ExitCode::FAILURE, $this->execute('queue:monitor', ['--max=2']));
        $this->assertStringContainsString('over the limit', $this->printed);
    }

    public function test_monitor_stays_quiet_under_the_limit(): void
    {
        $this->fill('default', 1);

        $this->assertSame(ExitCode::SUCCESS, $this->execute('queue:monitor', ['--max=10']));
    }

    public function test_monitor_takes_several_queues(): void
    {
        $this->fill('high', 2);
        $this->fill('low', 1);

        $this->execute('queue:monitor', ['--queue=high,low']);

        $this->assertStringContainsString('high', $this->printed);
        $this->assertStringContainsString('low', $this->printed);
    }

    // ─── clear ────────────────────────────────────────────

    /** Destructive and not undoable, so it asks first. */
    public function test_clear_refuses_without_force(): void
    {
        $this->fill('default', 2);

        $this->assertSame(ExitCode::FAILURE, $this->execute('queue:clear'));
        $this->assertStringContainsString('--force', $this->printed);
        $this->assertSame(2, $this->queues->connection('sync')->size('default'), 'nothing was deleted');
    }

    public function test_clear_empties_the_queue_when_forced(): void
    {
        $this->fill('default', 3);

        $this->assertSame(ExitCode::SUCCESS, $this->execute('queue:clear', ['--force']));
        $this->assertSame(0, $this->queues->connection('sync')->size('default'));
    }

    public function test_clear_leaves_other_queues_alone(): void
    {
        $this->fill('default', 2);
        $this->fill('reports', 2);

        $this->execute('queue:clear', ['--force']);

        $this->assertSame(0, $this->queues->connection('sync')->size('default'));
        $this->assertSame(2, $this->queues->connection('sync')->size('reports'));
    }

    public function test_clearing_an_empty_queue_says_so(): void
    {
        $this->assertSame(ExitCode::SUCCESS, $this->execute('queue:clear', ['--force']));
        $this->assertStringContainsString('Nothing', $this->printed);
    }

    // ─── the signatures ───────────────────────────────────

    public function test_every_new_signature_is_advertised(): void
    {
        foreach (['queue:pause', 'queue:resume', 'queue:monitor', 'queue:clear'] as $signature) {
            $this->assertArrayHasKey($signature, QueueCommands::COMMANDS);
        }
    }
}

class ControlJob extends Job
{
    public function handle(): void {}
}
