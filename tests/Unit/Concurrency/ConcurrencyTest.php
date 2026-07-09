<?php

namespace Tests\Unit\Concurrency;

use Nitro\Concurrency\Concurrency;
use Nitro\Concurrency\Console\ConcurrencyInvokeCommand;
use Nitro\Concurrency\Drivers\ProcessDriver;
use Nitro\Concurrency\Drivers\SyncDriver;
use Nitro\Concurrency\HttpResult;
use Nitro\Concurrency\TaskInvoker;
use PHPUnit\Framework\TestCase;

/** An invokable task used to exercise the class-string form. */
class DoubleTask
{
    public function __invoke(): int
    {
        return 21 * 2;
    }
}

/** A task with named methods used to exercise the [class, method, args] form. */
class MathTask
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}

class ConcurrencyTest extends TestCase
{
    public function test_sync_driver_runs_all_task_shapes_and_preserves_keys(): void
    {
        $results = (new SyncDriver())->run([
            'closure' => fn () => 'hi',
            'invoke'  => DoubleTask::class,
            'method'  => [MathTask::class, 'add', [2, 3]],
        ]);

        $this->assertSame('hi', $results['closure']);
        $this->assertSame(42, $results['invoke']);
        $this->assertSame(5, $results['method']);
        $this->assertSame(['closure', 'invoke', 'method'], array_keys($results));
    }

    public function test_task_invoker_serializability(): void
    {
        $this->assertFalse(TaskInvoker::isSerializable(fn () => 1));
        $this->assertTrue(TaskInvoker::isSerializable(DoubleTask::class));
        $this->assertTrue(TaskInvoker::isSerializable([MathTask::class, 'add', [1, 2]]));
        $this->assertFalse(TaskInvoker::isSerializable([MathTask::class, 'x', [fn () => 1]]));
    }

    public function test_manager_run_uses_sync_driver_when_configured(): void
    {
        $manager = new Concurrency('sync');

        $out = $manager->run([
            'a' => fn () => 1,
            'b' => [MathTask::class, 'add', [10, 5]],
        ]);

        $this->assertSame(['a' => 1, 'b' => 15], $out);
    }

    public function test_driver_resolution_is_memoized(): void
    {
        $manager = new Concurrency('sync');

        $this->assertInstanceOf(SyncDriver::class, $manager->driver('sync'));
        $this->assertInstanceOf(ProcessDriver::class, $manager->driver('process'));
        $this->assertSame($manager->driver('sync'), $manager->driver('sync'));
    }

    public function test_unknown_driver_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Concurrency())->driver('nope');
    }

    public function test_process_driver_rejects_closures(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProcessDriver())->run(['x' => fn () => 1]);
    }

    /**
     * Drives curl_multi end-to-end without external network: an unreachable local
     * port must come back as a keyed HttpResult marked failed(), not throw.
     */
    public function test_http_returns_keyed_results_and_reports_transport_errors(): void
    {
        if (! function_exists('curl_multi_init')) {
            $this->markTestSkipped('cURL extension not available.');
        }

        $results = (new Concurrency())->http([
            'refused' => ['url' => 'http://127.0.0.1:9/', 'timeout' => 2],
        ]);

        $this->assertArrayHasKey('refused', $results);
        $this->assertInstanceOf(HttpResult::class, $results['refused']);
        $this->assertTrue($results['refused']->failed());
        $this->assertFalse($results['refused']->ok());
        $this->assertNotNull($results['refused']->error);
    }

    /**
     * The wire protocol the process driver relies on: a task serialised into the
     * command, run, wrapped in sentinels, then extracted (even buried in console
     * banner noise) and unserialised back — all without spawning a real subprocess.
     */
    public function test_invoke_command_output_round_trips_through_extraction(): void
    {
        $payload = base64_encode(serialize(DoubleTask::class));

        // Wrap the command's real output in banner/noise to prove extraction is robust.
        $output = (new ConcurrencyInvokeCommand())->render($payload);
        $stdout = "startup banner\n" . $output . "\ntrailing noise";

        $decoded = $this->extractResult($stdout);
        $this->assertTrue($decoded['ok']);
        $this->assertSame(42, unserialize(base64_decode($decoded['result'])));
    }

    public function test_invoke_command_reports_task_failure(): void
    {
        // A task referencing a missing class fails during invoke.
        $payload = base64_encode(serialize([\Nitro\Concurrency\NoSuchTask::class, 'run', []]));

        $decoded = $this->extractResult((new ConcurrencyInvokeCommand())->render($payload));

        $this->assertFalse($decoded['ok']);
        $this->assertNotEmpty($decoded['error']);
    }

    private function extractResult(string $stdout): array
    {
        $method = new \ReflectionMethod(ProcessDriver::class, 'extractResult');
        $method->setAccessible(true);

        return $method->invoke(new ProcessDriver(), $stdout);
    }

    public function test_http_result_helpers(): void
    {
        $ok = new HttpResult(200, ['content-type' => 'application/json'], '{"a":1}');
        $this->assertTrue($ok->ok());
        $this->assertFalse($ok->failed());
        $this->assertSame(['a' => 1], $ok->json());
        $this->assertSame('application/json', $ok->header('Content-Type'));

        $err = new HttpResult(0, [], '', 'connect timed out');
        $this->assertTrue($err->failed());
        $this->assertFalse($err->ok());
        $this->assertNull($err->header('x-missing'));
    }
}
