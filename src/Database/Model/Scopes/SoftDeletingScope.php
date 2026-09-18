<?php

namespace Nitro\Database\Model\Scopes;

use Nitro\Database\Model\Model;
use Nitro\Database\Model\ModelBuilder;
use Nitro\Database\Model\Scope;

/**
 * Hides soft-deleted rows from every query for a model using SoftDeletes.
 *
 * Opt out with withTrashed() or onlyTrashed().
 */
class SoftDeletingScope implements Scope
{
    public function apply(ModelBuilder $builder, Model $model): void
    {
        $builder->whereNull($model->getDeletedAtColumn());
    }
}
