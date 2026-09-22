<?php

namespace Nitro\Inertia\Props;

use Nitro\Inertia\Concerns\ResolvesCallables;
use Nitro\Inertia\Contracts\IgnoreFirstLoad;

/**
 * A prop evaluated only when a partial reload asks for it by name.
 *
 * The callback does not run on first render, so an expensive value costs
 * nothing until something on the page actually wants it.
 */
class OptionalProp implements IgnoreFirstLoad
{
    use ResolvesCallables;

    /** @var callable */
    protected $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function __invoke(): mixed
    {
        return $this->resolveCallable($this->callback);
    }
}
