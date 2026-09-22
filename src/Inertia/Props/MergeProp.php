<?php

namespace Nitro\Inertia\Props;

use Nitro\Inertia\Concerns\MergesProps;
use Nitro\Inertia\Concerns\ResolvesCallables;
use Nitro\Inertia\Contracts\Mergeable;

/**
 * A prop whose value is added to what the page already has.
 *
 * Sent on first load like any other prop; the difference is what a later
 * partial reload does with it. Page two of a list arrives and is appended,
 * rather than replacing page one — which is the whole of "load more".
 */
class MergeProp implements Mergeable
{
    use MergesProps;
    use ResolvesCallables;

    public function __construct(protected mixed $value)
    {
        $this->merge = true;
    }

    public function __invoke(): mixed
    {
        return $this->resolveCallable($this->value);
    }
}
