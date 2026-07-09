<?php

namespace Nitro\Facades;

/**
 * Concurrency facade — per-request task fan-out.
 *
 *   Concurrency::http(['https://a.test', 'https://b.test']);   // parallel HTTP (curl_multi)
 *   Concurrency::run([Report::class, [Sync::class, 'pull']]);  // parallel tasks (process driver)
 *   Concurrency::defer([[Mailer::class, 'flush']]);            // fire-and-forget
 *
 * This is NOT coroutines — it parallelises a few tasks within one request, not
 * concurrent requests. A coroutine layer is planned separately.
 *
 * @method static array run(array $tasks, ?int $timeout = null)
 * @method static array http(array $requests, array $defaults = [])
 * @method static void  defer(array $tasks)
 * @method static \Nitro\Concurrency\Contracts\Driver driver(?string $name = null)
 */
class Concurrency extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'concurrency';
    }
}
