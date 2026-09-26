<?php

namespace Nitro\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * A global scope named by #[ScopedBy].
 */
class PublishedScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
    }
}
