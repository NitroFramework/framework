<?php

namespace Tests\Unit\Exceptions;

use Nitro\Cache\Repository;
use Nitro\Cache\Drivers\ArrayStore;
use Nitro\Container\Container;
use Nitro\Exceptions\ExceptionHandler;
use Nitro\Exceptions\ReportRate;
use Nitro\Foundation\Contracts\ConfigRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Report throttling. One broken dependency throwing in a loop should leave a
 * few diagnostic lines in the log, not fill the disk.
 */
class ReportThrottleTest extends TestCase
{
    private function handler(): ExceptionHandler
    {
        $container = new Container();
        $container->instance('cache', new Repository(new ArrayStore()));

        return new ExceptionHandler(new ThrottleConfig(), $container);
    }

    public function test_no_throttle_is_configured_by_default(): void
    {
        $handler = $this->handler();

        for ($i = 0; $i < 50; $i++) {
            $this->assertTrue($handler->shouldReport(new RuntimeException('boom')));
        }
    }

    public function test_a_limit_stops_reporting_once_the_budget_is_spent(): void
    {
        $handler = $this->handler();
        $handler->throttle(fn() => ReportRate::limit(3, 60));

        $allowed = 0;
        for ($i = 0; $i < 20; $i++) {
            if ($handler->shouldReport(new RuntimeException('boom'))) {
                $allowed++;
            }
        }

        $this->assertSame(3, $allowed, 'Only the first three occurrences should be reported.');
    }

    public function test_separate_exception_types_get_separate_budgets(): void
    {
        $handler = $this->handler();
        $handler->throttle(fn() => ReportRate::limit(2, 60));

        $this->assertTrue($handler->shouldReport(new RuntimeException('a')));
        $this->assertTrue($handler->shouldReport(new RuntimeException('a')));
        $this->assertFalse($handler->shouldReport(new RuntimeException('a')));

        // A different class has its own budget and is unaffected.
        $this->assertTrue($handler->shouldReport(new ThrottleOther('b')));
    }

    public function test_a_shared_key_pools_the_budget(): void
    {
        $handler = $this->handler();
        $handler->throttle(fn() => ReportRate::limit(2, 60, 'shared'));

        $this->assertTrue($handler->shouldReport(new RuntimeException('a')));
        $this->assertTrue($handler->shouldReport(new ThrottleOther('b')));
        // Both drew on the same budget, so the third is refused whichever type.
        $this->assertFalse($handler->shouldReport(new RuntimeException('c')));
    }

    public function test_unlimited_never_throttles(): void
    {
        $handler = $this->handler();
        $handler->throttle(fn() => ReportRate::unlimited());

        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue($handler->shouldReport(new RuntimeException('boom')));
        }
    }

    public function test_a_zero_sample_rate_reports_nothing(): void
    {
        $handler = $this->handler();
        $handler->throttle(fn() => ReportRate::sample(0.0));

        $this->assertFalse($handler->shouldReport(new RuntimeException('boom')));
    }

    public function test_a_full_sample_rate_reports_everything(): void
    {
        $handler = $this->handler();
        $handler->throttle(fn() => ReportRate::sample(1.0));

        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue($handler->shouldReport(new RuntimeException('boom')));
        }
    }

    public function test_a_callback_returning_null_leaves_the_exception_alone(): void
    {
        $handler = $this->handler();
        $handler->throttle(fn(\Throwable $e) => $e instanceof ThrottleOther ? ReportRate::limit(1) : null);

        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($handler->shouldReport(new RuntimeException('unthrottled')));
        }

        $this->assertTrue($handler->shouldReport(new ThrottleOther('x')));
        $this->assertFalse($handler->shouldReport(new ThrottleOther('x')));
    }

    public function test_throttling_fails_open_when_the_cache_is_unavailable(): void
    {
        // No 'cache' binding at all — losing a log line because the cache is
        // down is the wrong trade, so the exception must still be reported.
        $handler = new ExceptionHandler(new ThrottleConfig(), new Container());
        $handler->throttle(fn() => ReportRate::limit(1, 60));

        $this->assertTrue($handler->shouldReport(new RuntimeException('boom')));
        $this->assertTrue($handler->shouldReport(new RuntimeException('boom')));
    }
}

class ThrottleConfig implements ConfigRepository
{
    private array $items = ['app.debug' => false];

    public function has(string $key): bool { return array_key_exists($key, $this->items); }
    public function get(string $key, mixed $default = null): mixed { return $this->items[$key] ?? $default; }
    public function all(): array { return $this->items; }
    public function set(string $key, mixed $value): void { $this->items[$key] = $value; }
}

class ThrottleOther extends RuntimeException {}
