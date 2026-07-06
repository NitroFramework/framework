<?php

namespace Nitro\Database\Model\Relations;

use Nitro\Support\Collection;
use Nitro\Database\Model\BaseModel;
use Nitro\Database\Model\ModelBuilder;
use Nitro\Database\Query\QueryBuilder;

/**
 * Base for tagged relation objects. Subclasses know their own kind
 * (hasOne, hasMany, belongsTo, …) so eager-loading doesn't have to
 * sniff WHERE/JOIN structure to guess. They also know which keys connect
 * parent and child, which the inferred path got wrong whenever a relation
 * used custom keys or added query constraints.
 *
 * A Relation IS a ModelBuilder for syntactic compatibility (so callers
 * can still chain ->where(), ->orderBy(), ->with()), but it carries
 * extra metadata RelationLoader reads to do batched IN(…) lookups.
 */
abstract class Relation extends ModelBuilder
{
    protected BaseModel $parent;

    /**
     * Set up the underlying ModelBuilder by deferring to the parent
     * ModelBuilder constructor, then store the parent model. Subclasses
     * configure the query (where, join, etc.) AFTER calling parent::__construct.
     */
    public function __construct(BaseModel $parent, QueryBuilder $query, string $relatedClass)
    {
        parent::__construct($query, $relatedClass);
        $this->parent = $parent;
    }

    public function getParent(): BaseModel
    {
        return $this->parent;
    }

    /**
     * Snapshot of the relation BEFORE the per-parent WHERE constraint
     * is applied. Eager loading needs to wipe that constraint (it's
     * specific to one parent) and replace it with whereIn(...). Subclasses
     * track it via $this->baseQuery in their constructor.
     */
    abstract public function eagerLoad(array $parents, string $relationName, ?string $nested): void;

    /**
     * Result hydration helper — shared by every loader implementation.
     */
    protected function hydrate(array $rows): array
    {
        $modelClass = $this->modelClass;
        $instance = new $modelClass;
        $models = [];
        foreach ($rows as $row) {
            $models[] = $instance->newFromObject($row);
        }
        return $models;
    }

    /**
     * Apply user-added constraints (the ones that lived on the relation
     * before the per-parent WHERE) to a fresh eager query. Subclasses
     * snapshot the relevant builder state in their constructor and replay
     * it here. The default just returns a clone of $this->query.
     */
    protected function freshQuery(): QueryBuilder
    {
        return clone $this->query;
    }
}
