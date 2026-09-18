<?php

namespace Nitro\Facades;

/**
 * Pipeline facade — passes a value through a series of stages.
 *
 *   Pipeline::send($request)->through($middleware)->then(fn ($r) => $r);
 *
 * @method static \Nitro\Support\Pipeline send(mixed $passable)
 * @method static \Nitro\Support\Pipeline through(mixed $pipes)
 * @method static \Nitro\Support\Pipeline pipe(mixed $pipes)
 * @method static \Nitro\Support\Pipeline via(string $method)
 * @method static mixed then(\Closure $destination)
 * @method static mixed thenReturn()
 */
class Pipeline extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'pipeline';
    }
}
