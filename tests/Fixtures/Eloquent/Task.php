<?php

namespace Nitro\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;

/**
 * A child of a model that overrides resolveObserveAttributes(): Laravel runs the parent's
 * override, which reaches Laravel's method with this class, which in turn asks the parent again.
 */
#[ObservedBy(CommentObserver::class)]
class Task extends Ticket
{
}
