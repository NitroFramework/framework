<?php

namespace Tests\Unit\Debug;

use Nitro\Container\Container;
use Nitro\Debug\Backtrace;
use Nitro\Debug\TraceRenderer;
use Nitro\Http\Response;
use PHPUnit\Framework\TestCase;

/** The call stack, returned from wherever you are rather than thrown. */
class TraceTest extends TestCase
{
    protected function setUp(): void
    {
        Container::setInstance(new Container());
    }

    protected function tearDown(): void
    {
        Container::setInstance(new Container());
    }

    // ── The sequence ──────────────────────────────────────────────────

    /**
     * The frames read as the path that led here, entry first.
     *
     * The opposite of how PHP numbers a trace, and the order the calls
     * actually happened in.
     */
    public function test_the_frames_are_in_the_order_they_were_called(): void
    {
        $data = (new Caller())->outer();

        $calls = array_column($data['frames'], 'call');

        $this->assertSame(
            ['Tests\Unit\Debug\Caller->outer()', 'Tests\Unit\Debug\Caller->middle()', 'Tests\Unit\Debug\Caller->inner()'],
            array_slice($calls, -3),
        );
    }

    public function test_each_frame_names_its_file_and_line(): void
    {
        $frames = (new Caller())->outer()['frames'];

        $innermost = end($frames);

        $this->assertStringEndsWith('TraceTest.php', (string) $innermost['file']);
        $this->assertIsInt($innermost['line']);
    }

    public function test_the_steps_are_numbered_from_one(): void
    {
        $frames = (new Caller())->outer()['frames'];

        $this->assertSame(1, $frames[0]['step']);
        $this->assertSame(count($frames), end($frames)['step']);
    }

    /** Asking for it the other way gives PHP's order. */
    public function test_the_order_can_be_reversed(): void
    {
        $data = (new Caller())->outer(newestFirst: true);

        $this->assertSame('Tests\Unit\Debug\Caller->inner()', $data['frames'][0]['call']);
    }

    // ── What a frame carries ──────────────────────────────────────────

    /**
     * Arguments are summarised, not dumped.
     *
     * One of them is usually a request or a container, and printing it
     * would bury the frame it belongs to.
     */
    public function test_arguments_are_summarised(): void
    {
        $data = (new Caller())->outer();

        $middle = $this->frameFor($data, 'middle');

        $this->assertStringContainsString('"a-name"', $middle['args']);
        $this->assertStringContainsString('Array(2)', $middle['args']);
    }

    public function test_an_object_argument_is_named_by_its_class(): void
    {
        $data = (new Caller())->withObject(new \stdClass());

        $this->assertStringContainsString('stdClass', $this->frameFor($data, 'withObject')['args']);
    }

    /** A long string is cut rather than filling the line. */
    public function test_a_long_string_argument_is_shortened(): void
    {
        $data = (new Caller())->withObject(str_repeat('x', 200));

        $this->assertStringContainsString('…', $this->frameFor($data, 'withObject')['args']);
    }

    // ── Where it was taken ────────────────────────────────────────────

    /**
     * The helper's own frame is dropped, and its line kept separately.
     *
     * The frames are the path through the application; the line the
     * helper was put on is the one thing they do not say.
     */
    public function test_the_origin_is_reported_and_not_a_frame(): void
    {
        $data = (new Caller())->outer();

        $this->assertNotNull($data['taken_at']);
        $this->assertStringContainsString('TraceTest.php', (string) $data['taken_at']);

        foreach ($data['frames'] as $frame) {
            $this->assertStringNotContainsString('trace_renderer', (string) $frame['call']);
        }
    }

    public function test_a_label_is_carried_through(): void
    {
        $this->assertSame('why here', (new Caller())->outer('why here')['label']);
    }

    // ── Returning it ──────────────────────────────────────────────────

    /** The point of it: a controller returns this instead of a view. */
    public function test_it_returns_a_response(): void
    {
        $response = trace('from a test');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertStringContainsString('text/html', (string) $response->header('Content-Type'));
        $this->assertStringContainsString('from a test', $response->getContent());
    }

    /**
     * The wrapper helpers do not appear in their own output.
     *
     * trace() calls through a second function to build the renderer, so
     * without skipping both the trace shows its own plumbing and the
     * origin points at the helper file rather than the caller.
     */
    public function test_the_helper_does_not_show_itself(): void
    {
        $text = (new Caller())->text('a note');

        $this->assertStringNotContainsString('trace_text()', $text);
        $this->assertStringNotContainsString('trace_renderer()', $text);
        $this->assertStringNotContainsString('Helpers\trace.php', $text);
    }

    /** And the origin is the line the helper was put on. */
    public function test_the_origin_is_the_callers_line_not_the_helpers(): void
    {
        $response = trace();

        $this->assertStringContainsString('TraceTest.php', $response->getContent());
        $this->assertStringNotContainsString(
            'taken at C:\xampp\htdocs\nitro-framework\src\Support\Helpers',
            $response->getContent(),
        );
    }

    public function test_the_html_lists_every_frame(): void
    {
        $html = (new Caller())->html();

        $this->assertStringContainsString('Caller-&gt;innerHtml()', $html);
        $this->assertStringContainsString('Caller-&gt;html()', $html);
    }

    public function test_the_text_form_is_readable(): void
    {
        $text = (new Caller())->text('a note');

        $this->assertStringContainsString('Stack trace — a note', $text);
        $this->assertStringContainsString('Caller->innerText()', $text);
    }

    // ── From an exception ─────────────────────────────────────────────

    /** A trace already thrown can be shown the same way. */
    public function test_a_throwable_can_be_rendered(): void
    {
        $renderer = new TraceRenderer(Backtrace::fromThrowable(new \RuntimeException('boom')));

        $this->assertGreaterThan(0, $renderer->toArray()['depth']);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function frameFor(array $data, string $method): array
    {
        foreach ($data['frames'] as $frame) {
            if (str_contains((string) $frame['call'], '->' . $method . '(')) {
                return $frame;
            }
        }

        $this->fail("no frame for {$method}");
    }
}

/** A chain deep enough for the trace to have something to show. */
class Caller
{
    /** @return array<string, mixed> */
    public function outer(?string $label = null, bool $newestFirst = false): array
    {
        return $this->middle('a-name', ['one', 'two'], $label, $newestFirst);
    }

    /** @return array<string, mixed> */
    private function middle(string $name, array $options, ?string $label, bool $newestFirst): array
    {
        return $this->inner($label, $newestFirst);
    }

    /** @return array<string, mixed> */
    private function inner(?string $label, bool $newestFirst): array
    {
        return trace_renderer($label)->toArray($newestFirst);
    }

    /** @return array<string, mixed> */
    public function withObject(mixed $argument): array
    {
        return trace_renderer(null)->toArray();
    }

    public function html(): string
    {
        return $this->innerHtml();
    }

    private function innerHtml(): string
    {
        return trace_renderer(null)->toHtml();
    }

    public function text(string $label): string
    {
        return $this->innerText($label);
    }

    private function innerText(string $label): string
    {
        return trace_text($label);
    }
}
