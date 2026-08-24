<?php

namespace Tests\Unit\Exceptions;

use Nitro\Container\Container;
use Nitro\Database\Model\ModelNotFoundException;
use Nitro\Exceptions\ExceptionHandler;
use Nitro\Exceptions\HttpException;
use Nitro\Foundation\Contracts\ConfigRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The exception pipeline: map → report → prepare → render.
 *
 * Each stage used to be missing or half-wired — every non-HTTP exception was a
 * 500, a report callback could not stop the default log, and an exception could
 * not describe itself. These lock in the corrected behaviour.
 */
class ExceptionPipelineTest extends TestCase
{
    private function handler(array $config = []): ExceptionHandler
    {
        return new ExceptionHandler(
            new PipelineConfig($config + ['app.debug' => false]),
            new Container()
        );
    }

    // ─── prepareException / status codes ────────────────────────────────────

    public function test_http_exception_keeps_its_own_status(): void
    {
        $this->assertSame(419, $this->handler()->getStatusCode(new HttpException(419, 'Expired')));
    }

    public function test_a_missing_model_becomes_a_404_not_a_500(): void
    {
        $e = ModelNotFoundException::forModel('App\\Models\\Student', 42);

        $this->assertSame(404, $this->handler()->getStatusCode($e));
    }

    public function test_an_unmapped_exception_is_still_a_500(): void
    {
        $this->assertSame(500, $this->handler()->getStatusCode(new RuntimeException('boom')));
    }

    public function test_prepare_wraps_the_original_as_previous(): void
    {
        $original = ModelNotFoundException::forModel('App\\Models\\Student', 7);
        $prepared = $this->handler()->prepareException($original);

        $this->assertInstanceOf(HttpException::class, $prepared);
        $this->assertSame($original, $prepared->getPrevious());
    }

    // ─── map() ──────────────────────────────────────────────────────────────

    public function test_map_rewrites_one_exception_into_another(): void
    {
        $handler = $this->handler();
        $handler->map(RuntimeException::class, fn(RuntimeException $e) => new HttpException(503, 'Down'));

        $this->assertSame(503, $handler->getStatusCode(new RuntimeException('db gone')));
    }

    public function test_map_accepts_a_target_class(): void
    {
        $handler = $this->handler();
        $handler->map(PipelineFailure::class, PipelineMapped::class);

        $mapped = $handler->mapException(new PipelineFailure('original'));

        $this->assertInstanceOf(PipelineMapped::class, $mapped);
        $this->assertSame('original', $mapped->getMessage());
    }

    // ─── report suppression ─────────────────────────────────────────────────

    public function test_http_exceptions_are_not_reported_by_default(): void
    {
        $this->assertFalse($this->handler()->shouldReport(new HttpException(404, 'Missing')));
    }

    public function test_ordinary_exceptions_are_reported(): void
    {
        $this->assertTrue($this->handler()->shouldReport(new RuntimeException('boom')));
    }

    public function test_stop_ignoring_puts_a_class_back_into_reporting(): void
    {
        $handler = $this->handler();
        $handler->stopIgnoring(HttpException::class);

        $this->assertTrue($handler->shouldReport(new HttpException(404, 'Missing')));
    }

    public function test_dont_report_when_suppresses_by_predicate(): void
    {
        $handler = $this->handler();
        $handler->dontReportWhen(fn(\Throwable $e) => str_contains($e->getMessage(), 'expected'));

        $this->assertFalse($handler->shouldReport(new RuntimeException('an expected blip')));
        $this->assertTrue($handler->shouldReport(new RuntimeException('a real problem')));
    }

    public function test_duplicates_are_reported_once(): void
    {
        $handler = $this->handler();
        $handler->dontReportDuplicates();

        $seen = 0;
        $handler->reportUsing(RuntimeException::class, function () use (&$seen) {
            $seen++;
        });

        $e = new RuntimeException('boom');
        $handler->report($e);
        $handler->report($e);

        $this->assertSame(1, $seen, 'The same instance must only be reported once.');
    }

    // ─── report handlers ────────────────────────────────────────────────────

    public function test_a_report_callback_claims_the_exception(): void
    {
        $handler = $this->handler();
        $called = false;

        $handler->reportUsing(RuntimeException::class, function () use (&$called) {
            $called = true;
            // Returning anything but false means "handled".
        });

        $handler->report(new RuntimeException('boom'));

        $this->assertTrue($called);
    }

    public function test_a_report_callback_returning_false_falls_through(): void
    {
        $handler = $this->handler();
        $order = [];

        $handler->reportUsing(RuntimeException::class, function () use (&$order) {
            $order[] = 'callback';
            return false; // not handled — keep going
        });

        $handler->report(new RuntimeException('boom'));

        $this->assertSame(['callback'], $order);
    }

    public function test_an_exception_can_report_itself(): void
    {
        PipelineSelfReporting::$reported = 0;

        $this->handler()->report(new PipelineSelfReporting('boom'));

        $this->assertSame(1, PipelineSelfReporting::$reported);
    }

    // ─── levels and context ─────────────────────────────────────────────────

    public function test_the_default_log_level_is_error(): void
    {
        $this->assertSame('error', $this->handler()->levelFor(new RuntimeException('boom')));
    }

    public function test_level_can_be_mapped_per_exception_class(): void
    {
        $handler = $this->handler();
        $handler->level(HttpException::class, 'warning');

        $this->assertSame('warning', $handler->levelFor(new HttpException(429, 'Slow down')));
    }

    public function test_context_carries_the_origin_of_the_failure(): void
    {
        $context = $this->handler()->contextFor(new RuntimeException('boom'));

        $this->assertSame(RuntimeException::class, $context['exception']);
        $this->assertArrayHasKey('file', $context);
        $this->assertArrayHasKey('line', $context);
    }

    public function test_an_exception_contributes_its_own_context(): void
    {
        $e = ModelNotFoundException::forModel('App\\Models\\Student', 42);
        $context = $this->handler()->contextFor($e);

        $this->assertSame('App\\Models\\Student', $context['model']);
        $this->assertSame([42], $context['ids']);
    }

    public function test_build_context_using_adds_to_every_exception(): void
    {
        $handler = $this->handler();
        $handler->buildContextUsing(fn() => ['tenant' => 'acme']);

        $this->assertSame('acme', $handler->contextFor(new RuntimeException('boom'))['tenant']);
    }

    public function test_context_omits_the_trace_unless_debugging(): void
    {
        $this->assertArrayNotHasKey('trace', $this->handler()->contextFor(new RuntimeException('x')));

        $debug = $this->handler(['app.debug' => true]);
        $this->assertArrayHasKey('trace', $debug->contextFor(new RuntimeException('x')));
    }

    // ─── rendering ──────────────────────────────────────────────────────────

    public function test_an_exception_can_render_itself(): void
    {
        $this->assertSame('<p>handled inline</p>', $this->handler()->render(new PipelineSelfRendering('x')));
    }

    public function test_the_production_page_uses_status_appropriate_copy(): void
    {
        $html = $this->handler()->render(new HttpException(404, 'Missing'));

        $this->assertStringContainsString('Page Not Found', $html);
        $this->assertStringContainsString("couldn&#039;t find that page", $html);
        // The old page told every visitor we were "experiencing technical
        // difficulties", including on a 404.
        $this->assertStringNotContainsString('technical difficulties', $html);
    }

    public function test_a_500_still_reads_as_a_server_error(): void
    {
        $html = $this->handler()->render(new RuntimeException('boom'));

        $this->assertStringContainsString('500', $html);
        $this->assertStringContainsString('Server Error', $html);
        // A production page must never leak the exception message.
        $this->assertStringNotContainsString('boom', $html);
    }

    public function test_console_output_carries_the_location(): void
    {
        $out = $this->handler()->renderForConsole(new RuntimeException('boom'));

        $this->assertStringContainsString('RuntimeException: boom', $out);
        $this->assertStringContainsString(__FILE__, $out);
    }

    public function test_console_output_includes_a_trace_when_debugging(): void
    {
        $debug = $this->handler(['app.debug' => true]);

        $this->assertStringContainsString('#0', $debug->renderForConsole(new RuntimeException('boom')));
    }

    // ─── respondUsing (global finalize) ─────────────────────────────────────

    public function test_respond_using_post_processes_the_response(): void
    {
        $handler = $this->handler();
        $handler->respondUsing(fn($response, $e, $request) => $response . '+finalized');

        $this->assertSame('body+finalized', $handler->finalize('body', new RuntimeException('x')));
    }

    public function test_finalize_is_a_no_op_without_a_callback(): void
    {
        $this->assertSame('body', $this->handler()->finalize('body', new RuntimeException('x')));
    }
}

/** Minimal ConfigRepository over a flat dot-keyed array. */
class PipelineConfig implements ConfigRepository
{
    public function __construct(private array $items = []) {}

    public function has(string $key): bool { return array_key_exists($key, $this->items); }
    public function get(string $key, mixed $default = null): mixed { return $this->items[$key] ?? $default; }
    public function all(): array { return $this->items; }
    public function set(string $key, mixed $value): void { $this->items[$key] = $value; }
}

class PipelineFailure extends RuntimeException {}
class PipelineMapped extends RuntimeException {}

class PipelineSelfReporting extends RuntimeException
{
    public static int $reported = 0;

    public function report(): void
    {
        static::$reported++;
    }
}

class PipelineSelfRendering extends RuntimeException
{
    public function render($request): string
    {
        return '<p>handled inline</p>';
    }
}
