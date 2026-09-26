<?php

namespace Nitro\Tests\Fixtures\Eloquent;

/**
 * An observer named by #[ObservedBy].
 */
class ArticleObserver
{
    public function creating(): void
    {
    }

    public function deleted(): void
    {
    }
}
