<?php

namespace Nitro\Inertia\Props;

use Nitro\Inertia\Concerns\ResolvesCallables;
use Nitro\Inertia\Concerns\ResolvesOnce;
use Nitro\Inertia\Contracts\Onceable;

/**
 * A prop sent once and then remembered by the client.
 *
 * The callback runs on the first page that needs it and not again while the
 * client still holds the value, so this suits things that are expensive to
 * build and stable for a session.
 */
class OnceProp implements Onceable
{
    use ResolvesCallables;
    use ResolvesOnce;

    /** @var callable */
    protected $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
        $this->once = true;
    }

    public function __invoke(): mixed
    {
        return $this->resolveCallable($this->callback);
    }
}
