<?php

namespace Nitro\Facades;

/**
 * ParallelTesting facade — hooks for a suite split across processes.
 *
 *   ParallelTesting::setUpProcess(fn () => Artisan::call('migrate:fresh'));
 *
 * @method static \Nitro\Testing\ParallelTesting setUpProcess(\Closure $callback)
 * @method static \Nitro\Testing\ParallelTesting setUpTestCase(\Closure $callback)
 * @method static \Nitro\Testing\ParallelTesting setUpTestDatabase(\Closure $callback)
 * @method static \Nitro\Testing\ParallelTesting tearDownTestCase(\Closure $callback)
 * @method static \Nitro\Testing\ParallelTesting tearDownProcess(\Closure $callback)
 * @method static void callHook(string $hook, mixed ...$arguments)
 * @method static string|false token()
 * @method static bool inParallel()
 * @method static string tokenise(string $name)
 */
class ParallelTesting extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'parallel.testing';
    }
}
