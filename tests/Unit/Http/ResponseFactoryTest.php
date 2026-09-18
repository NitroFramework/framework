<?php

namespace Tests\Unit\Http;

use JsonSerializable;
use Nitro\Http\RedirectResponse;
use Nitro\Http\Redirector;
use Nitro\Http\Response;
use Nitro\Http\ResponseFactory;
use Nitro\View\View;
use Nitro\View\Factory;
use PHPUnit\Framework\TestCase;

/**
 * The response factory, and the JSON it puts on the wire.
 *
 * An endpoint answering `true` or a bare string is legitimate JSON. The factory
 * used to wrap anything that was not an array, so `true` left as `[true]` — a
 * shape no consumer asked for and nothing in the suite noticed.
 */
class ResponseFactoryTest extends TestCase
{
    private function factory(?Factory $view = null): ResponseFactory
    {
        return new ResponseFactory(
            $view ?? $this->createMock(Factory::class),
            $this->createMock(Redirector::class),
        );
    }

    // ─── JSON ─────────────────────────────────────────────

    public function test_an_array_encodes_as_an_object(): void
    {
        $this->assertSame('{"ok":true}', $this->factory()->json(['ok' => true])->getContent());
    }

    public function test_a_list_encodes_as_an_array(): void
    {
        $this->assertSame('[1,2,3]', $this->factory()->json([1, 2, 3])->getContent());
    }

    public function test_a_boolean_is_not_wrapped(): void
    {
        $this->assertSame('true', $this->factory()->json(true)->getContent());
    }

    public function test_a_string_is_not_wrapped(): void
    {
        $this->assertSame('"accepted"', $this->factory()->json('accepted')->getContent());
    }

    public function test_a_number_is_not_wrapped(): void
    {
        $this->assertSame('42', $this->factory()->json(42)->getContent());
    }

    public function test_null_is_not_wrapped(): void
    {
        $this->assertSame('null', $this->factory()->json(null)->getContent());
    }

    public function test_a_json_serializable_object_decides_its_own_shape(): void
    {
        $data = new class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['id' => 7];
            }
        };

        $this->assertSame('{"id":7}', $this->factory()->json($data)->getContent());
    }

    public function test_an_object_with_to_array_is_converted(): void
    {
        $data = new class {
            public function toArray(): array
            {
                return ['id' => 9];
            }
        };

        $this->assertSame('{"id":9}', $this->factory()->json($data)->getContent());
    }

    public function test_encoding_flags_are_honoured(): void
    {
        $this->assertSame(
            '{"a":{"b":1}}',
            $this->factory()->json(['a' => ['b' => 1]])->getContent(),
        );

        $this->assertStringContainsString(
            "\n",
            $this->factory()->json(['a' => ['b' => 1]], 200, [], JSON_PRETTY_PRINT)->getContent(),
        );
    }

    public function test_json_carries_its_content_type_and_status(): void
    {
        $response = $this->factory()->json(['ok' => true], 201);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('application/json; charset=utf-8', $response->header('Content-Type'));
    }

    public function test_json_accepts_extra_headers(): void
    {
        $response = $this->factory()->json([], 200, ['X-Trace' => 'abc']);

        $this->assertSame('abc', $response->header('X-Trace'));
    }

    // ─── Headers on every builder ─────────────────────────

    public function test_make_applies_headers(): void
    {
        $response = $this->factory()->make('hi', 200, ['X-Trace' => 'abc']);

        $this->assertSame('hi', $response->getContent());
        $this->assertSame('abc', $response->header('X-Trace'));
    }

    public function test_no_content_applies_headers(): void
    {
        $response = $this->factory()->noContent(204, ['X-Trace' => 'abc']);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        $this->assertSame('abc', $response->header('X-Trace'));
    }

    // ─── Views ────────────────────────────────────────────

    public function test_a_view_is_rendered_through_the_injected_factory(): void
    {
        $view = $this->createMock(View::class);
        $view->method('render')->willReturn('<p>hello</p>');

        $views = $this->createMock(Factory::class);
        $views->expects($this->once())
            ->method('make')
            ->with('orders.show', ['id' => 1])
            ->willReturn($view);

        $response = $this->factory($views)->view('orders.show', ['id' => 1], 200, ['X-Trace' => 'abc']);

        $this->assertSame('<p>hello</p>', $response->getContent());
        $this->assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        $this->assertSame('abc', $response->header('X-Trace'));
    }

    /** No global helper is reached for, so the class stands up without an application. */
    public function test_it_needs_no_application_booted_around_it(): void
    {
        $this->assertInstanceOf(Response::class, $this->factory()->make('plain'));
    }

    // ─── Redirects ────────────────────────────────────────

    public function test_redirects_go_through_the_injected_redirector(): void
    {
        $redirector = $this->createMock(Redirector::class);
        $redirector->expects($this->once())
            ->method('to')
            ->with('/home', 302)
            ->willReturn(new RedirectResponse('/home'));

        $factory = new ResponseFactory($this->createMock(Factory::class), $redirector);

        $this->assertInstanceOf(RedirectResponse::class, $factory->redirectTo('/home'));
    }
}
