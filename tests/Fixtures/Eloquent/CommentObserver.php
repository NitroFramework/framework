<?php

namespace Nitro\Tests\Fixtures\Eloquent;

/**
 * An observer named by #[ObservedBy] on a model whose parent names another.
 */
class CommentObserver
{
    public function saved(): void
    {
    }
}
