<?php

namespace Nitro\Facades;

/**
 * Gate facade — authorises actions against the current user.
 *
 *   Gate::allows('update', $post);
 *   Gate::define('admin', fn ($user) => $user->isAdmin());
 *
 * @method static void define(string $ability, callable|string $callback)
 * @method static bool allows(string $ability, mixed $arguments = [])
 * @method static bool denies(string $ability, mixed $arguments = [])
 * @method static bool check(string $ability, mixed $arguments = [])
 * @method static void authorize(string $ability, mixed $arguments = [])
 * @method static void policy(string $class, string $policy)
 * @method static void before(callable $callback)
 * @method static void after(callable $callback)
 */
class Gate extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'gate';
    }
}
