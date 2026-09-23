<?php

namespace Tests\Unit\Http;

use Nitro\Http\Exceptions\PostTooLargeException;
use Nitro\Http\Middleware\ConvertEmptyStringsToNull;
use Nitro\Http\Middleware\TrimStrings;
use Nitro\Http\Middleware\ValidatePostSize;
use Nitro\Http\Request;
use Nitro\Http\Response;
use PHPUnit\Framework\TestCase;

/** The middleware that rewrite input before anything reads it. */
class TransformsRequestTest extends TestCase
{
    protected function tearDown(): void
    {
        TrimStrings::flushState();
        ConvertEmptyStringsToNull::flushState();
    }

    private function request(array $body = [], array $query = [], string $method = 'POST'): Request
    {
        return new Request($method, '/submit', [], $query, $body);
    }

    /** Runs the middleware and hands back the request as it was left. */
    private function through(object $middleware, Request $request): Request
    {
        $seen = null;

        $middleware->handle($request, function (Request $passed) use (&$seen): Response {
            $seen = $passed;

            return Response::html('ok');
        });

        return $seen;
    }

    // --- trimming -----------------------------------------------------------

    public function test_surrounding_whitespace_is_stripped(): void
    {
        $request = $this->through(new TrimStrings(), $this->request(['email' => '  ada@example.com  ']));

        $this->assertSame('ada@example.com', $request->post('email'));
    }

    /** Query parameters too, or the same field behaves differently by verb. */
    public function test_query_parameters_are_trimmed(): void
    {
        $request = $this->through(new TrimStrings(), $this->request([], ['search' => ' ada '], 'GET'));

        $this->assertSame('ada', $request->query('search'));
    }

    public function test_nested_values_are_trimmed(): void
    {
        $request = $this->through(new TrimStrings(), $this->request([
            'contact' => ['name' => '  Ada  ', 'tags' => ['  one  ']],
        ]));

        $this->assertSame('Ada', $request->post('contact')['name']);
        $this->assertSame('one', $request->post('contact')['tags'][0]);
    }

    /** Passwords are left exactly as typed. */
    public function test_passwords_are_never_trimmed(): void
    {
        $request = $this->through(new TrimStrings(), $this->request([
            'password'              => ' secret ',
            'password_confirmation' => ' secret ',
            'current_password'      => ' old ',
        ]));

        $this->assertSame(' secret ', $request->post('password'));
        $this->assertSame(' secret ', $request->post('password_confirmation'));
        $this->assertSame(' old ', $request->post('current_password'));
    }

    public function test_non_strings_are_left_alone(): void
    {
        $request = $this->through(new TrimStrings(), $this->request(['age' => 30, 'ok' => true, 'none' => null]));

        $this->assertSame(30, $request->post('age'));
        $this->assertTrue($request->post('ok'));
        $this->assertNull($request->post('none'));
    }

    /** An application can exempt more fields, by pattern. */
    public function test_fields_can_be_exempted_globally_by_pattern(): void
    {
        TrimStrings::except('signature.*');

        $request = $this->through(new TrimStrings(), $this->request([
            'signature' => ['blob' => '  keep  '],
            'name'      => '  Ada  ',
        ]));

        $this->assertSame('  keep  ', $request->post('signature')['blob']);
        $this->assertSame('Ada', $request->post('name'));
    }

    public function test_the_whole_middleware_can_be_skipped(): void
    {
        TrimStrings::skipWhen(static fn (Request $request): bool => $request->path() === '/submit');

        $request = $this->through(new TrimStrings(), $this->request(['name' => '  Ada  ']));

        $this->assertSame('  Ada  ', $request->post('name'));
    }

    // --- empty strings ------------------------------------------------------

    public function test_empty_strings_become_null(): void
    {
        $request = $this->through(new ConvertEmptyStringsToNull(), $this->request(['nickname' => '']));

        $this->assertNull($request->post('nickname'));
    }

    /** A zero is a value, not an absence. */
    public function test_zero_and_false_survive(): void
    {
        $request = $this->through(new ConvertEmptyStringsToNull(), $this->request([
            'count' => 0,
            'zero'  => '0',
            'off'   => false,
        ]));

        $this->assertSame(0, $request->post('count'));
        $this->assertSame('0', $request->post('zero'));
        $this->assertFalse($request->post('off'));
    }

    public function test_nested_empty_strings_become_null(): void
    {
        $request = $this->through(new ConvertEmptyStringsToNull(), $this->request([
            'contact' => ['name' => '', 'city' => 'London'],
        ]));

        $this->assertNull($request->post('contact')['name']);
        $this->assertSame('London', $request->post('contact')['city']);
    }

    // --- post size ----------------------------------------------------------

    /**
     * A body larger than PHP accepts is reported as that, rather than as a
     * form where everything is missing.
     */
    public function test_an_oversized_body_is_refused(): void
    {
        $request = new Request('POST', '/upload', [], [], [], [], ['CONTENT_LENGTH' => (string) (PHP_INT_MAX)]);

        $middleware = new class extends ValidatePostSize {
            protected function postMaxSize(): int
            {
                return 1024;
            }
        };

        $this->expectException(PostTooLargeException::class);

        $middleware->handle($request, static fn (): Response => Response::html('never reached'));
    }

    public function test_a_body_within_the_limit_passes(): void
    {
        $request = new Request('POST', '/upload', [], [], [], [], ['CONTENT_LENGTH' => '512']);

        $middleware = new class extends ValidatePostSize {
            protected function postMaxSize(): int
            {
                return 1024;
            }
        };

        $response = $middleware->handle($request, static fn (): Response => Response::html('ok'));

        $this->assertSame('ok', $response->getContent());
    }

    /** A limit of zero means unlimited, so nothing is refused. */
    public function test_no_limit_refuses_nothing(): void
    {
        $request = new Request('POST', '/upload', [], [], [], [], ['CONTENT_LENGTH' => '999999999']);

        $middleware = new class extends ValidatePostSize {
            protected function postMaxSize(): int
            {
                return 0;
            }
        };

        $this->assertSame(
            'ok',
            $middleware->handle($request, static fn (): Response => Response::html('ok'))->getContent()
        );
    }
}
