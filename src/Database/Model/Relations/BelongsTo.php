<?php

namespace Nitro\Database\Model\Relations;

use Nitro\Database\DB;
use Nitro\Database\Model\BaseModel;
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
        BaseModel $parent,
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

        $fk = $this->foreignKey;
        $idSet = [];
        foreach ($parents as $p) {
            $v = $p->{$fk};
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

        foreach ($parents as $p) {
            $p->setRelation($relationName, $indexed[$p->{$fk}] ?? null);
        }

        if ($nested && !empty($hydrated)) {
            RelationLoader::load($hydrated, [$nested]);
        }
    }
}
