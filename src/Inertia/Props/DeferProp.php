<?php

namespace Nitro\Inertia\Props;

use Nitro\Inertia\Concerns\DefersProps;
use Nitro\Inertia\Concerns\MergesProps;
use Nitro\Inertia\Concerns\ResolvesCallables;
use Nitro\Inertia\Contracts\Deferrable;
use Nitro\Inertia\Contracts\IgnoreFirstLoad;
use Nitro\Inertia\Contracts\Mergeable;
use Nitro\Inertia\Contracts\Rescuable;

/**
 * A prop the page renders without and then fetches for itself.
 *
 * Unlike {@see OptionalProp}, the client is told this one exists: the response
 * lists it under `deferredProps` by group, and the page requests each group as
 * soon as it has mounted. The user sees the page, then sees it fill in.
 */
class DeferProp implements Deferrable, IgnoreFirstLoad, Mergeable, Rescuable
{
    use DefersProps;
    use MergesProps;
    use ResolvesCallables;

    /** @var callable */
    protected $callback;

    public function __construct(callable $callback, ?string $group = null, private bool $rescue = false)
    {
        $this->callback = $callback;

        $this->defer($group);
    }

    /**
     * Whether a failure here should be contained.
     *
     * The page is already rendered by the time this resolves, so an
     * unhandled failure would replace a working page with an error over a
     * value it was designed to arrive without.
     */
    public function shouldRescue(): bool
    {
        return $this->rescue;
    }

    public function __invoke(): mixed
    {
        return $this->resolveCallable($this->callback);
    }
}
