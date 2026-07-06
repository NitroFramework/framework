<?php

namespace Nitro\Database\Model\Relations;

use Nitro\Support\Collection;
use Nitro\Database\DB;
use Nitro\Database\Model\BaseModel;
use Nitro\Database\Model\RelationLoader;

/**
 * One parent → many children: parent.{ownerKey} = child.{foreignKey}.
 * The constructor pre-applies the single-parent WHERE so chained queries
 * like $user->posts()->where('status', 'published')->get() work directly.
 * For eager loading, the loader strips the per-parent WHERE via
 * cloneWithoutFirstWhere() and substitutes whereIn(ids).
 */
class HasMany extends Relation
{
    protected string $foreignKey;
    protected string $ownerKey;

    public function __construct(
        BaseModel $parent,
        string $relatedClass,
        string $foreignKey,
        string $ownerKey
    ) {
        $instance = new $relatedClass;
        $parentValue = $parent->{$ownerKey};
        $query = DB::table($instance->getTable())->where($foreignKey, $parentValue);

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
            $empty = new Collection();
            foreach ($parents as $p) {
                $p->setRelation($relationName, $empty);
            }
            return;
        }
        $ids = array_keys($idSet);

        $eagerQuery = $this->query->cloneWithoutFirstWhere();
        $eagerQuery->whereIn($this->foreignKey, $ids);

        $rows = $eagerQuery->get()->all();
        $hydrated = $this->hydrate($rows);

        $fkShort = self::shortColumn($this->foreignKey);

        $grouped = [];
        foreach ($hydrated as $row) {
            $grouped[$row->{$fkShort}][] = $row;
        }

        foreach ($parents as $p) {
            $key = $p->{$owner};
            $p->setRelation($relationName, new Collection($grouped[$key] ?? []));
        }

        if ($nested && !empty($hydrated)) {
            RelationLoader::load($hydrated, [$nested]);
        }
    }

    protected static function shortColumn(string $col): string
    {
        return str_contains($col, '.') ? substr($col, strrpos($col, '.') + 1) : $col;
    }
}
