<?php

namespace Tests\Unit\Concurrency;

use Nitro\Concurrency\Concurrency;
use Nitro\Concurrency\Contracts\Driver;
use Nitro\Concurrency\Drivers\ForkDriver;
use Nitro\Concurrency\Drivers\ProcessDriver;
use Nitro\Concurrency\Drivers\SyncDriver;
use Nitro\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Deferring work, and the driver that forks instead of spawning.
 *
 * defer() lived on the manager alone and wrote its child's output to 'NUL' —
 * the Windows null device. On Linux that name is not special, so every
 * deferred task left a file called NUL in the application root instead of
 * discarding anything.
 *
 * It also belongs on the contract: a driver that can fork defers by forking,
 * which costs nothing next to booting a second PHP process.
 */
class ConcurrencyDriversTest extends TestCase
{
    private ?Container $previous = null;

    /**
     * TaskInvoker reaches for app(), so these need a container of their own
     * rather than whichever earlier test class happened to leave one behind.
     */
    protected function setUp(): void
    {
        $this->previous = Container::hasInstance() ? Container::getInstance() : null;

        Container::setInstance(new Container());
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
    }

    // ─── the null device ──────────────────────────────────────────────────

    /** The one that was wrong: NUL is Windows, /dev/null is everywhere else. */
    public function test_the_null_device_matches_the_platform(): void
    {
        $device = (new ReflectionMethod(ProcessDriver::class, 'nullDevice'))->invoke(null);

        $this->assertSame(
            DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null',
            $device,
        );
    }

    public function test_no_driver_hardcodes_the_windows_null_device(): void
    {
        $offenders = [];

        foreach (glob(dirname(__DIR__, 3) . '/src/Concurrency/{,*/}*.php', GLOB_BRACE) ?: [] as $file) {
            $source = (string) file_get_contents($file);

            /* The helper itself is allowed to name both. */
            if (str_contains($source, 'function nullDevice')) {
                continue;
            }

            if (preg_match("/'NUL'/", $source)) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'these name the Windows null device directly');
    }

    // ─── defer on the contract ────────────────────────────────────────────

    public function test_the_contract_declares_defer(): void
    {
        $declared = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(Driver::class))->getMethods(),
        );

        sort($declared);

        $this->assertSame(['defer', 'run'], $declared);
    }

    #[DataProvider('drivers')]
    public function test_every_driver_implements_the_contract(string $driver): void
    {
        $this->assertTrue(
            (new ReflectionClass($driver))->implementsInterface(Driver::class),
            "{$driver} must implement the Driver contract",
        );
    }

    public static function drivers(): array
    {
        return [[SyncDriver::class], [ProcessDriver::class], [ForkDriver::class]];
    }

    /** Sync defers by simply doing it — there is nowhere else to put the work. */
    public function test_the_sync_driver_defers_by_running_now(): void
    {
        $ran = new \stdClass();
        $ran->value = false;

        (new SyncDriver())->defer([function () use ($ran) {
            $ran->value = true;
        }]);

        $this->assertTrue($ran->value, 'sync has no background, so defer runs it');
    }

    /** And it still returns results from run(), keyed as given. */
    public function test_the_sync_driver_keeps_task_keys(): void
    {
        $results = (new SyncDriver())->run([
            'a' => fn () => 1,
            'b' => fn () => 2,
        ]);

        $this->assertSame(['a' => 1, 'b' => 2], $results);
    }

    // ─── fork driver ──────────────────────────────────────────────────────

    /** pcntl does not exist on Windows, so the driver has to say so itself. */
    public function test_the_fork_driver_reports_whether_it_can_run(): void
    {
        $this->assertSame(
            function_exists('pcntl_fork') && function_exists('pcntl_waitpid'),
            ForkDriver::supported(),
        );
    }

    public function test_the_manager_refuses_fork_where_it_is_unsupported(): void
    {
        if (ForkDriver::supported()) {
            $this->markTestSkipped('pcntl is available, so there is nothing to refuse');
        }

        $this->expectException(\RuntimeException::class);

        (new Concurrency('sync'))->driver('fork');
    }

    public function test_the_manager_knows_the_fork_driver(): void
    {
        if (! ForkDriver::supported()) {
            $this->markTestSkipped('pcntl is unavailable on this platform');
        }

        $this->assertInstanceOf(ForkDriver::class, (new Concurrency('sync'))->driver('fork'));
    }

    public function test_forked_tasks_return_their_results(): void
    {
        if (! ForkDriver::supported()) {
            $this->markTestSkipped('pcntl is unavailable on this platform');
        }

        $results = (new ForkDriver())->run([
            'a' => fn () => 6 * 7,
            'b' => fn () => strtoupper('ok'),
        ]);

        $this->assertSame(['a' => 42, 'b' => 'OK'], $results);
    }

    public function test_an_unknown_driver_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Concurrency('sync'))->driver('telepathy');
    }
}
