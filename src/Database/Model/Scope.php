<?php

namespace Nitro\Database\Model;

/**
 * A constraint applied to every query for a model.
 *
 *     class PublishedScope implements Scope
 *     {
 *         public function apply(ModelBuilder $builder, Model $model): void
 *         {
 *             $builder->whereNotNull($model->qualifyColumn('published_at'));
 *         }
 *     }
 *
 *     Post::addGlobalScope(new PublishedScope());
 */
interface Scope
{
    /**
     * Apply the constraint to a query.
     *
     * @param ModelBuilder $builder Query being built.
     * @param Model        $model   Model the query is for.
     */
    public function apply(ModelBuilder $builder, Model $model): void;
}
