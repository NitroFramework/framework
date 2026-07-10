<?php

namespace Nitro\Database\Model\Relations;

use Nitro\Database\DB;
use Nitro\Database\Model\Model;
use Nitro\Database\Model\RelationLoader;

/**
 * One parent → one child. Same wiring as HasMany except eager loading
 * sets a single related model on each parent instead of a Collection.
 */
class HasOne extends Relation
{
    protected string $foreignKey;
    protected string $ownerKey;

    public function __construct(
        Model $parent,
        string $relatedClass,
        string $foreignKey,
        string $ownerKey
    ) {
        $instance = new $relatedClass;
        $parentValue = $parent->{$ownerKey};
        $query = DB::table($instance->getTable())
            ->where($foreignKey, $parentValue)
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

        $owner = $this->ownerKey;
        $idSet = [];
        foreach ($parents as $p) {
            $v = $p->{$owner};
            if ($v !== null && $v !== '') {
                $idSet[$v] = true;
            }
        }
        if (empty($idSet)) {
            foreach ($parents as $p) {
                $p->setRelation($relationName, null);
            }
            return;
        }

        $eagerQuery = $this->query->cloneWithoutFirstWhere();
        $eagerQuery->whereIn($this->foreignKey, array_keys($idSet));

        $rows = $eagerQuery->get()->all();
        $hydrated = $this->hydrate($rows);

        $fkShort = str_contains($this->foreignKey, '.')
            ? substr($this->foreignKey, strrpos($this->foreignKey, '.') + 1)
            : $this->foreignKey;

        // First-row wins per parent — hasOne semantics.
        $indexed = [];
        foreach ($hydrated as $row) {
            $key = $row->{$fkShort};
            if (!isset($indexed[$key])) {
                $indexed[$key] = $row;
            }
        }

        foreach ($parents as $p) {
            $p->setRelation($relationName, $indexed[$p->{$owner}] ?? null);
        }

        if ($nested && !empty($hydrated)) {
            RelationLoader::load($hydrated, [$nested]);
        }
    }
}
