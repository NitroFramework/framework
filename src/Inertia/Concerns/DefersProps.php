<?php

namespace Nitro\Inertia\Concerns;

/**
 * Whether a prop is held back from the first render, and what it is fetched
 * alongside.
 *
 * Opt-in per instance rather than per class: a {@see \Nitro\Inertia\Props\ScrollProp}
 * is Deferrable so that it *can* be deferred, but its first page has to render
 * with the page or there is nothing to scroll.
 */
trait DefersProps
{
    protected bool $deferred = false;

    protected ?string $deferGroup = null;

    /**
     * Hold this prop back, in the named group.
     *
     * The client makes one request per group, so props that belong together
     * share a group and arrive in one round trip.
     *
     * @return static
     */
    public function defer(?string $group = null)
    {
        $this->deferred = true;
        $this->deferGroup = $group;

        return $this;
    }

    public function shouldDefer(): bool
    {
        return $this->deferred;
    }

    public function group(): string
    {
        return $this->deferGroup ?? 'default';
    }
}
