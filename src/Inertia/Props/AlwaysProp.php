<?php

namespace Nitro\Inertia\Props;

use Nitro\Inertia\Concerns\ResolvesCallables;

/**
 * A prop sent with every response, including partial reloads that did not ask
 * for it.
 *
 * Validation errors are the case this exists for: a form submission that comes
 * back asking only for one prop still has to carry the errors, or the page
 * would clear them and the user would see their input rejected with no reason
 * shown.
 */
class AlwaysProp
{
    use ResolvesCallables;

    public function __construct(protected mixed $value)
    {
    }

    public function __invoke(): mixed
    {
        return $this->resolveCallable($this->value);
    }
}
