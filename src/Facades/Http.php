<?php

namespace Nitro\Facades;

use Nitro\Http\Client\PendingRequest;
use Nitro\Http\Client\Response;

/**
 * Http facade — proxies the outgoing HTTP client.
 *
 *   Http::withToken($token)->get('https://api.example.test/users');
 *   Http::fake(['api.example.test/*' => Http::response(['ok' => true])]);
 *
 * @method static Response get(string $url, array $query = [])
 * @method static Response post(string $url, array|string $data = [])
 * @method static Response put(string $url, array|string $data = [])
 * @method static Response patch(string $url, array|string $data = [])
 * @method static Response delete(string $url, array|string $data = [])
 * @method static Response head(string $url, array $query = [])
 * @method static PendingRequest withHeaders(array $headers)
 * @method static PendingRequest withHeader(string $name, string $value)
 * @method static PendingRequest withToken(string $token, string $type = 'Bearer')
 * @method static PendingRequest withBasicAuth(string $username, string $password)
 * @method static PendingRequest accept(string $contentType)
 * @method static PendingRequest acceptJson()
 * @method static PendingRequest asJson()
 * @method static PendingRequest asForm()
 * @method static PendingRequest asMultipart()
 * @method static PendingRequest baseUrl(string $url)
 * @method static PendingRequest timeout(int $seconds)
 * @method static PendingRequest connectTimeout(int $seconds)
 * @method static PendingRequest retry(int $times, int $sleep = 0, ?\Closure $when = null)
 * @method static PendingRequest withoutVerifying()
 * @method static PendingRequest throw(?\Closure $callback = null)
 * @method static \Nitro\Http\Client\Factory fake(array|\Closure|Response|null $stubs = null)
 * @method static \Nitro\Http\Client\Factory stopFaking()
 * @method static \Nitro\Http\Client\Factory preventStrayRequests(bool $prevent = true)
 * @method static Response response(array|string|null $body = null, int $status = 200, array $headers = [])
 * @method static void assertSent(\Closure $callback)
 * @method static void assertNotSent(\Closure $callback)
 * @method static void assertNothingSent()
 * @method static void assertSentCount(int $count)
 * @method static array recorded()
 */
class Http extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'http.client';
    }
}
