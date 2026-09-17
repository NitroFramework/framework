<?php

namespace Tests\Unit\Exceptions;

use Nitro\Container\Container;
use Nitro\Exceptions\ExceptionHandler;
use Nitro\Foundation\Contracts\ConfigRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Registration by callback signature, reporting state, context, and the
 * queue-retry decisions.
 */
class HandlerSurfaceTest extends TestCase
{
    private ExceptionHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        Container::reset();

        $config = new class implements ConfigRepository {
            public function get(string $key, mixed $default = null): mixed { return $default; }
            public function set(string $key, mixed $value): void {}
            public function has(string $key): bool { return false; }
            public function all(): array { return []; }
        };

        $this->handler = new ExceptionHandler($config, new Container());
    }

    // ─── reportable() ─────────────────────────────────────

    /** The type comes from the callback's parameter, not a second argument. */
    public function test_reportable_registers_by_parameter_type(): void
    {
        $seen = null;

        $this->handler->reportable(function (PaymentFailed $exception) use (&$seen) {
            $seen = $exception;

            return true;
        });

        $exception = new PaymentFailed('card declined');
        $this->handler->report($exception);

        $this->assertSame($exception, $seen);
    }

    public function test_reportable_ignores_other_types(): void
    {
        $called = false;

        $this->handler->reportable(function (PaymentFailed $exception) use (&$called) {
            $called = true;

            return true;
        });

        $this->handler->report(new RuntimeException('unrelated'));

        $this->assertFalse($called);
    }

    // ─── renderable() ─────────────────────────────────────

    public function test_renderable_registers_by_parameter_type(): void
    {
        $this->handler->renderable(function (PaymentFailed $exception) {
            return 'rendered: ' . $exception->getMessage();
        });

        $this->assertSame(
            'rendered: declined',
            $this->handler->renderResponse(new PaymentFailed('declined'), null)
        );
    }

    public function test_renderable_declines_other_types(): void
    {
        $this->handler->renderable(function (PaymentFailed $exception) {
            return 'handled';
        });

        $this->assertNull($this->handler->renderResponse(new RuntimeException('other'), null));
    }

    // ─── ignore() ─────────────────────────────────────────

    public function test_ignore_suppresses_reporting(): void
    {
        $this->handler->ignore(PaymentFailed::class);

        $this->assertTrue($this->handler->shouldntReport(new PaymentFailed('x')));
        $this->assertFalse($this->handler->shouldntReport(new RuntimeException('x')));
    }

    // ─── isReporting() ────────────────────────────────────

    public function test_is_reporting_is_true_only_during_a_report(): void
    {
        $during = null;

        $this->handler->reportable(function (PaymentFailed $exception) use (&$during) {
            $during = $this->handler->isReporting();

            return true;
        });

        $this->assertFalse($this->handler->isReporting());

        $this->handler->report(new PaymentFailed('x'));

        $this->assertTrue($during, 'isReporting() must be true inside a reporter');
        $this->assertFalse($this->handler->isReporting(), 'and false again afterwards');
    }

    /**
     * A reporter that throws must not leave the handler believing it is still
     * reporting, or everything logged afterwards would be suppressed.
     */
    public function test_is_reporting_resets_when_a_reporter_throws(): void
    {
        $this->handler->reportable(function (PaymentFailed $exception) {
            throw new RuntimeException('reporter blew up');
        });

        try {
            $this->handler->report(new PaymentFailed('x'));
        } catch (\Throwable) {
            // The reporter's failure is not what this test is about.
        }

        $this->assertFalse($this->handler->isReporting());
    }

    // ─── Context ──────────────────────────────────────────

    public function test_context_for_exception_includes_the_basics(): void
    {
        $context = $this->handler->contextForException(new RuntimeException('boom'));

        $this->assertSame(RuntimeException::class, $context['exception']);
        $this->assertArrayHasKey('file', $context);
        $this->assertArrayHasKey('line', $context);
    }

    public function test_build_context_for_exception_reads_only_the_exception(): void
    {
        $context = $this->handler->buildContextForException(new PaymentFailed('x'));

        $this->assertSame(['order' => 42], $context);
    }

    public function test_build_context_is_empty_without_a_context_method(): void
    {
        $this->assertSame([], $this->handler->buildContextForException(new RuntimeException('x')));
    }

    /** The exception's own context is folded into the full context. */
    public function test_exception_context_reaches_the_full_context(): void
    {
        $context = $this->handler->contextForException(new PaymentFailed('x'));

        $this->assertSame(42, $context['order']);
    }

    // ─── Queue retries ────────────────────────────────────

    public function test_dont_retry_by_type(): void
    {
        $this->handler->dontRetry(PaymentFailed::class);

        $this->assertTrue($this->handler->shouldStopRetries(new PaymentFailed('x')));
        $this->assertFalse($this->handler->shouldStopRetries(new RuntimeException('x')));
    }

    public function test_dont_retry_when_by_predicate(): void
    {
        $this->handler->dontRetryWhen(
            static fn (\Throwable $exception) => str_contains($exception->getMessage(), 'permanent')
        );

        $this->assertTrue($this->handler->shouldStopRetries(new RuntimeException('permanent failure')));
        $this->assertFalse($this->handler->shouldStopRetries(new RuntimeException('transient blip')));
    }

    public function test_nothing_stops_retries_by_default(): void
    {
        $this->assertFalse($this->handler->shouldStopRetries(new RuntimeException('x')));
    }

    // ─── Aliases keep the originals working ───────────────

    public function test_throttle_using_is_the_throttle_callback(): void
    {
        $called = false;

        $this->handler->throttleUsing(function () use (&$called) {
            $called = true;

            return null;
        });

        $this->handler->report(new RuntimeException('x'));

        $this->assertTrue($called);
    }
}

class PaymentFailed extends RuntimeException
{
    /** @return array<string, mixed> */
    public function context(): array
    {
        return ['order' => 42];
    }
}
