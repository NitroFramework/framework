<?php

namespace Nitro\Database\Model;

use Nitro\Database\Model\Relations\Relation;

/**
 * Batched eager loader for `with()`. Walks each requested relation,
 * asks the relation object to load itself against the parent set, then
 * recurses into nested loads.
 *
 * The old loader sniffed query structure (number of wheres, presence of
 * joins) to guess hasOne/hasMany/belongsTo/etc. — that broke as soon as
 * a relation customized its query. The new flow asks the Relation
 * subclass directly: it knows its kind, its keys, and how to match
 * results back to parents.
 */
class RelationLoader
{
    public static function load(array &$models, array $relations): void
    {
        if (empty($models)) return;

        foreach ($relations as $relation) {
            $parts = explode('.', $relation, 2);
            $name = $parts[0];
            $nested = $parts[1] ?? null;

            static::loadRelation($models, $name, $nested);
        }
    }

    protected static function loadRelation(array &$models, string $name, ?string $nested): void
    {
        $sample = $models[0];

        if (!method_exists($sample, $name)) {
            throw new \RuntimeException(
                get_class($sample) . " does not have a relationship method: {$name}"
            );
        }

        $relation = $sample->{$name}();
        if (!($relation instanceof Relation)) {
            throw new \RuntimeException(
                get_class($sample) . "::{$name}() must return a "
                . Relation::class . " (HasOne/HasMany/BelongsTo/BelongsToMany/HasManyThrough). "
                . "Got: " . (is_object($relation) ? get_class($relation) : gettype($relation))
            );
        }

        $relation->eagerLoad($models, $name, $nested);
    }
}
