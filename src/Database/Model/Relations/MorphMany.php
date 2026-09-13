<?php

namespace Nitro\Database\Model\Relations;

use Nitro\Database\DB;
use Nitro\Database\Model\Model;

/**
 * One parent → many children, where the children can belong to more than one
 * kind of parent.
 *
 * Two columns on the child carry the link: an id and a type. A comments table
 * serving both courses and lessons holds commentable_id and commentable_type,
 * and this is the parent's side of it.
 *
 * The only difference from {@see HasMany} is the extra constraint on the type
 * column, so it inherits everything else — including the eager-loading path,
 * which is correct as-is because the type constraint survives
 * cloneWithoutFirstWhere() (it is not the first WHERE; the id is).
 */
class MorphMany extends HasMany
{
    protected string $typeColumn;

    public function __construct(
        Model $parent,
        string $relatedClass,
        string $foreignKey,
        string $typeColumn,
        string $ownerKey,
    ) {
        parent::__construct($parent, $relatedClass, $foreignKey, $ownerKey);

        $this->typeColumn = $typeColumn;

        // Order matters: the id WHERE was applied by the parent constructor and
        // must stay first, because the eager path strips exactly one WHERE and
        // expects it to be the per-parent one.
        $this->query->where($typeColumn, $parent->getMorphClass());
    }

    /** The column holding the parent's type. Read by whereHas()/withCount(). */
    public function getTypeColumn(): string
    {
        return $this->typeColumn;
    }
}
