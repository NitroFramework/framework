<?php

namespace Tests\Unit\Http;

use Nitro\Http\Client\ConnectionException;
use Nitro\Http\Client\Factory;
use Nitro\Http\Client\RequestException;
use Nitro\Http\Client\Response;
use PHPUnit\Framework\TestCase;

/**
 * The outgoing HTTP client: request building, responses, faking and retries.
 *
 * Every test fakes the transport, so nothing here touches the network.
 */
class HttpClientTest extends TestCase
{
    private Factory $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new Factory();
    }

    // ─── Responses ────────────────────────────────────────

    public function test_json_body_decodes(): void
    {
        $this->http->fake(['*' => $this->http->response(['name' => 'Ada', 'roles' => ['admin']])]);

        $response = $this->http->get('https://example.test/user');

        $this->assertSame('Ada', $response->json('name'));
        $this->assertSame('admin', $response->json('roles.0'));
        $this->assertSame(['name' => 'Ada', 'roles' => ['admin']], $response->json());
        $this->assertSame('application/json', $response->header('Content-Type'));
    }

    public function test_missing_json_path_returns_the_default(): void
    {
        $this->http->fake(['*' => $this->http->response(['a' => 1])]);

        $response = $this->http->get('https://example.test/');

        $this->assertNull($response->json('nope'));
        $this->assertSame('fallback', $response->json('deep.path', 'fallback'));
    }

    public function test_status_helpers(): void
    {
        $cases = [
            [200, 'successful'],
            [201, 'successful'],
            [301, 'redirect'],
            [404, 'notFound'],
            [401, 'unauthorized'],
            [403, 'forbidden'],
            [422, 'clientError'],
            [500, 'serverError'],
        ];

        foreach ($cases as [$status, $method]) {
            $this->http->fake(['*' => $this->http->response('', $status)]);

            $response = $this->http->get('https://example.test/');

            $this->assertTrue($response->{$method}(), "{$status} should report {$method}()");
            $this->assertSame($status, $response->status());
        }
    }

    public function test_failed_covers_client_and_server_errors(): void
    {
        foreach ([418, 503] as $status) {
            $this->http->fake(['*' => $this->http->response('', $status)]);

            $this->assertTrue($this->http->get('https://example.test/')->failed());
        }
    }

    public function test_array_access_reads_the_json_body(): void
    {
        $this->http->fake(['*' => $this->http->response(['token' => 'abc'])]);

        $response = $this->http->get('https://example.test/');

        $this->assertSame('abc', $response['token']);
        $this->assertTrue(isset($response['token']));
    }

    public function test_collect_wraps_the_body(): void
    {
        $this->http->fake(['*' => $this->http->response(['items' => [1, 2, 3]])]);

        $this->assertSame(3, $this->http->get('https://example.test/')->collect('items')->count());
    }

    // ─── Throwing ─────────────────────────────────────────

    public function test_throw_raises_on_failure(): void
    {
        $this->http->fake(['*' => $this->http->response('gone wrong', 500)]);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('500');

        $this->http->get('https://example.test/')->throw();
    }

    public function test_throw_is_a_no_op_on_success(): void
    {
        $this->http->fake(['*' => $this->http->response('fine', 200)]);

        $response = $this->http->get('https://example.test/')->throw();

        $this->assertSame('fine', $response->body());
    }

    public function test_throw_configured_on_the_request(): void
    {
        $this->http->fake(['*' => $this->http->response('', 422)]);

        $this->expectException(RequestException::class);

        $this->http->throw()->get('https://example.test/');
    }

    public function test_the_exception_carries_the_response(): void
    {
        $this->http->fake(['*' => $this->http->response(['error' => 'nope'], 400)]);

        try {
            $this->http->get('https://example.test/')->throw();
            $this->fail('expected a RequestException');
        } catch (RequestException $exception) {
            $this->assertSame(400, $exception->response->status());
            $this->assertSame('nope', $exception->response->json('error'));
        }
    }

    // ─── Request building ─────────────────────────────────

    public function test_query_parameters_reach_the_url(): void
    {
        $this->http->fake();

        $this->http->get('https://example.test/search', ['q' => 'ada', 'page' => 2]);

        $this->http->assertSent(
            fn (array $request): bool => $request['url'] === 'https://example.test/search?q=ada&page=2'
        );
    }

    public function test_json_is_the_default_body_format(): void
    {
        $this->http->fake();

        $this->http->post('https://example.test/orders', ['sku' => 'A1']);

        $this->http->assertSent(fn (array $request): bool
            => $request['body'] === '{"sku":"A1"}'
            && $request['headers']['Content-Type'] === 'application/json');
    }

    public function test_as_form_encodes_the_body(): void
    {
        $this->http->fake();

        $this->http->asForm()->post('https://example.test/orders', ['sku' => 'A1', 'qty' => 2]);

        $this->http->assertSent(fn (array $request): bool
            => $request['body'] === 'sku=A1&qty=2'
            && $request['headers']['Content-Type'] === 'application/x-www-form-urlencoded');
    }

    public function test_bearer_token_and_headers(): void
    {
        $this->http->fake();

        $this->http->withToken('secret')
            ->withHeaders(['X-Trace' => 'abc'])
            ->acceptJson()
            ->get('https://example.test/');

        $this->http->assertSent(fn (array $request): bool
            => $request['headers']['Authorization'] === 'Bearer secret'
            && $request['headers']['X-Trace'] === 'abc'
            && $request['headers']['Accept'] === 'application/json');
    }

    public function test_basic_auth(): void
    {
        $this->http->fake();

        $this->http->withBasicAuth('ada', 'lovelace')->get('https://example.test/');

        $this->http->assertSent(fn (array $request): bool
            => $request['headers']['Authorization'] === 'Basic ' . base64_encode('ada:lovelace'));
    }

    public function test_base_url_prefixes_relative_paths_only(): void
    {
        $this->http->fake();

        $this->http->baseUrl('https://api.example.test')->get('/users');
        $this->http->baseUrl('https://api.example.test')->get('https://other.test/thing');

        $this->http->assertSent(fn (array $r): bool => $r['url'] === 'https://api.example.test/users');
        $this->http->assertSent(fn (array $r): bool => $r['url'] === 'https://other.test/thing');
    }

    public function test_every_verb_sends_its_method(): void
    {
        $this->http->fake();

        foreach (['get', 'post', 'put', 'patch', 'delete', 'head'] as $verb) {
            $this->http->{$verb}('https://example.test/');
        }

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $method) {
            $this->http->assertSent(fn (array $request): bool => $request['method'] === $method);
        }
    }

    /** A fresh request each time: configuration must not leak between calls. */
    public function test_configuration_does_not_leak_between_requests(): void
    {
        $this->http->fake();

        $this->http->withToken('secret')->get('https://example.test/one');
        $this->http->get('https://example.test/two');

        $this->http->assertSent(fn (array $r): bool
            => $r['url'] === 'https://example.test/two' && ! isset($r['headers']['Authorization']));
    }

    // ─── Faking ───────────────────────────────────────────

    public function test_stubs_match_by_url_pattern(): void
    {
        $this->http->fake([
            'example.test/users*' => $this->http->response(['who' => 'users']),
            '*' => $this->http->response(['who' => 'everything else']),
        ]);

        $this->assertSame('users', $this->http->get('https://example.test/users/1')->json('who'));
        $this->assertSame('everything else', $this->http->get('https://example.test/orders')->json('who'));
    }

    public function test_a_stub_may_be_a_closure_over_the_request(): void
    {
        $this->http->fake([
            '*' => fn (array $request) => $this->http->response(['method' => $request['method']]),
        ]);

        $this->assertSame('POST', $this->http->post('https://example.test/')->json('method'));
    }

    public function test_stray_requests_can_be_prevented(): void
    {
        $this->http->fake(['example.test/allowed' => $this->http->response('ok')])
            ->preventStrayRequests();

        $this->assertSame('ok', $this->http->get('https://example.test/allowed')->body());

        $this->expectException(ConnectionException::class);

        $this->http->get('https://example.test/elsewhere');
    }

    public function test_assertions_about_what_was_sent(): void
    {
        $this->http->fake();

        $this->http->get('https://example.test/one');
        $this->http->get('https://example.test/two');

        $this->http->assertSentCount(2);
        $this->http->assertNotSent(fn (array $r): bool => $r['url'] === 'https://example.test/three');
    }

    public function test_assert_nothing_sent(): void
    {
        $this->http->fake();

        $this->http->assertNothingSent();

        $this->http->get('https://example.test/');

        $this->expectException(\RuntimeException::class);

        $this->http->assertNothingSent();
    }

    public function test_assert_sent_fails_when_nothing_matched(): void
    {
        $this->http->fake();

        $this->http->get('https://example.test/');

        $this->expectException(\RuntimeException::class);

        $this->http->assertSent(fn (array $r): bool => $r['method'] === 'DELETE');
    }

    public function test_faking_resets_the_recorded_requests(): void
    {
        $this->http->fake();
        $this->http->get('https://example.test/');
        $this->http->assertSentCount(1);

        $this->http->fake();

        $this->http->assertNothingSent();
    }

    // ─── Retries ──────────────────────────────────────────

    public function test_a_server_error_is_retried_up_to_the_limit(): void
    {
        $attempts = 0;

        $this->http->fake([
            '*' => function () use (&$attempts) {
                $attempts++;

                return $this->http->response('unavailable', 503);
            },
        ]);

        $response = $this->http->retry(3)->get('https://example.test/');

        $this->assertSame(3, $attempts);
        $this->assertSame(503, $response->status());
    }

    public function test_retrying_stops_once_a_response_succeeds(): void
    {
        $attempts = 0;

        $this->http->fake([
            '*' => function () use (&$attempts) {
                $attempts++;

                return $attempts < 2
                    ? $this->http->response('', 500)
                    : $this->http->response(['ok' => true]);
            },
        ]);

        $response = $this->http->retry(5)->get('https://example.test/');

        $this->assertSame(2, $attempts);
        $this->assertTrue($response->json('ok'));
    }

    public function test_a_client_error_is_not_retried(): void
    {
        $attempts = 0;

        $this->http->fake([
            '*' => function () use (&$attempts) {
                $attempts++;

                return $this->http->response('', 404);
            },
        ]);

        $this->http->retry(3)->get('https://example.test/');

        $this->assertSame(1, $attempts);
    }

    public function test_a_custom_condition_decides_what_is_retried(): void
    {
        $attempts = 0;

        $this->http->fake([
            '*' => function () use (&$attempts) {
                $attempts++;

                return $this->http->response('', 429);
            },
        ]);

        $this->http->retry(3, 0, fn ($result): bool
            => $result instanceof Response && $result->status() === 429)
            ->get('https://example.test/');

        $this->assertSame(3, $attempts);
    }

    // ─── Hooks ────────────────────────────────────────────

    public function test_before_sending_sees_the_request(): void
    {
        $this->http->fake();

        $seen = null;

        $this->http->beforeSending(function (array $request) use (&$seen): void {
            $seen = $request['url'];
        })->get('https://example.test/inspect');

        $this->assertSame('https://example.test/inspect', $seen);
    }
}
