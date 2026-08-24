<?php

namespace Nitro\Database\Model\Relations;

use Nitro\Database\DB;
use Nitro\Database\Model\Model;
use Nitro\Database\Model\RelationLoader;

/**
 * Child → parent: child.{foreignKey} = parent.{ownerKey}. The relation
 * looks up the SINGLE parent row by the FK value held on the child.
 */
class BelongsTo extends Relation
{
    protected string $foreignKey;   // column on the parent (child) model
    protected string $ownerKey;     // column on the related model (usually 'id')

    public function __construct(
        Model $parent,
        string $relatedClass,
        string $foreignKey,
        string $ownerKey
    ) {
        $instance = new $relatedClass;
        $foreignValue = $parent->{$foreignKey};
        $query = DB::table($instance->getTable())
            ->where($ownerKey, $foreignValue)
            ->limit(1);

        parent::__construct($parent, $query, $relatedClass);
        $this->foreignKey = $foreignKey;
        $this->ownerKey = $ownerKey;
    }

    public function getForeignKey(): string { return $this->foreignKey; }
    public function getOwnerKey(): string { return $this->ownerKey; }

    public function eagerLoad(array $parents, string $relationName, ?string $nested): void
    {
        if (empty($parents)) return;

        $foreignKey = $this->foreignKey;
        $idSet = [];
        foreach ($parents as $parent) {
            $value = $parent->{$foreignKey};
            if ($value !== null && $value !== '') {
                $idSet[$value] = true;
            }
        }
        if (empty($idSet)) {
            foreach ($parents as $parent) {
                $parent->setRelation($relationName, null);
            }
            return;
        }

        $eagerQuery = $this->query->cloneWithoutFirstWhere();
        $eagerQuery->whereIn($this->ownerKey, array_keys($idSet));

        $rows = $eagerQuery->get()->all();
        $hydrated = $this->hydrate($rows);

        $ownerShort = str_contains($this->ownerKey, '.')
            ? substr($this->ownerKey, strrpos($this->ownerKey, '.') + 1)
            : $this->ownerKey;

        $indexed = [];
        foreach ($hydrated as $row) {
            $indexed[$row->{$ownerShort}] = $row;
        }

        foreach ($parents as $parent) {
            $parent->setRelation($relationName, $indexed[$parent->{$foreignKey}] ?? null);
        }

        if ($nested && !empty($hydrated)) {
            RelationLoader::load($hydrated, [$nested]);
        }
    }
}
