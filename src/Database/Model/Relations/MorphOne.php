<?php

namespace Nitro\Database\Model\Relations;

use Nitro\Database\DB;
use Nitro\Database\Model\Model;

/**
 * One parent → one child, where the child can belong to more than one kind of
 * parent. The singular of {@see MorphMany}; see it for the column layout.
 */
class MorphOne extends HasOne
{
    public function __construct(
        Model $parent,
        string $relatedClass,
        string $foreignKey,
        string $typeColumn,
        string $ownerKey,
    ) {
        parent::__construct($parent, $relatedClass, $foreignKey, $ownerKey);

        // Applied after the parent constructor so the per-parent id WHERE stays
        // first — the eager path strips exactly one, and expects that one.
        $this->query->where($typeColumn, $parent->getMorphClass());
    }
}
