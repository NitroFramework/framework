<?php

namespace Nitro\Database\Model\Relations;

use Nitro\Support\Collection;
use Nitro\Database\DB;
use Nitro\Database\Model\Model;
use Nitro\Database\Model\RelationLoader;

/**
 * Many children reached through an intermediate model.
 * Country → User → Post: a country has many posts, through users.
 *
 *   country.id = user.country_id  (firstKey on intermediate)
 *   user.id    = post.user_id     (secondKey on related, against intermediate.localKey)
 */
class HasManyThrough extends Relation
{
    protected string $through;         // intermediate table
    protected string $firstKey;        // FK on intermediate referencing parent (e.g. user.country_id)
    protected string $secondKey;       // FK on related referencing intermediate (e.g. post.user_id)
    protected string $localKey;        // PK on parent (usually 'id')
    protected string $secondLocalKey;  // PK on intermediate (usually 'id')

    public function __construct(
        Model $parent,
        string $relatedClass,
        string $throughTable,
        string $firstKey,
        string $secondKey,
        string $localKey,
        string $secondLocalKey
    ) {
        $instance = new $relatedClass;
        $relatedTable = $instance->getTable();

        $parentValue = $parent->{$localKey};

        $query = DB::table($relatedTable)
            ->join(
                $throughTable,
                "{$throughTable}.{$secondLocalKey}",
                '=',
                "{$relatedTable}.{$secondKey}"
            )
            ->where("{$throughTable}.{$firstKey}", $parentValue);

        parent::__construct($parent, $query, $relatedClass);
        $this->through = $throughTable;
        $this->firstKey = $firstKey;
        $this->secondKey = $secondKey;
        $this->localKey = $localKey;
        $this->secondLocalKey = $secondLocalKey;
    }

    public function eagerLoad(array $parents, string $relationName, ?string $nested): void
    {
        if (empty($parents)) return;

        $local = $this->localKey;
        $idSet = [];
        foreach ($parents as $p) {
            $v = $p->{$local};
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

        $eagerQuery = $this->query->cloneWithoutFirstWhere();
        $eagerQuery->whereIn("{$this->through}.{$this->firstKey}", array_keys($idSet));
        $eagerQuery->addSelect("{$this->through}.{$this->firstKey} as nitro_through_parent_id");

        $rows = $eagerQuery->get()->all();
        $hydrated = $this->hydrate($rows);

        $grouped = [];
        foreach ($hydrated as $row) {
            $grouped[$row->nitro_through_parent_id][] = $row;
        }

        foreach ($parents as $p) {
            $p->setRelation($relationName, new Collection($grouped[$p->{$local}] ?? []));
        }

        if ($nested && !empty($hydrated)) {
            RelationLoader::load($hydrated, [$nested]);
        }
    }
}
