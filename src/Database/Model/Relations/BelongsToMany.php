<?php

namespace Nitro\Database\Model\Relations;

use Nitro\Support\Collection;
use Nitro\Database\DB;
use Nitro\Database\Model\BaseModel;
use Nitro\Database\Model\RelationLoader;

/**
 * Many-to-many through a pivot table. Pre-applies the single-parent
 * WHERE on the pivot column for direct use; eager loading swaps it for
 * whereIn() against the same pivot column, and projects the pivot's
 * "parent id" alongside related rows so we can fan results back out.
 */
class BelongsToMany extends Relation
{
    protected string $pivotTable;
    protected string $foreignPivotKey;   // column on pivot pointing at the parent
    protected string $relatedPivotKey;   // column on pivot pointing at related
    protected string $parentKey;         // owner key on parent
    protected string $relatedKey;        // owner key on related (usually 'id')

    public function __construct(
        BaseModel $parent,
        string $relatedClass,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey
    ) {
        $instance = new $relatedClass;
        $relatedTable = $instance->getTable();

        $parentValue = $parent->{$parentKey};

        $query = DB::table($relatedTable)
            ->join(
                $pivotTable,
                "{$relatedTable}.{$relatedKey}",
                '=',
                "{$pivotTable}.{$relatedPivotKey}"
            )
            ->where("{$pivotTable}.{$foreignPivotKey}", $parentValue);

        parent::__construct($parent, $query, $relatedClass);
        $this->pivotTable = $pivotTable;
        $this->foreignPivotKey = $foreignPivotKey;
        $this->relatedPivotKey = $relatedPivotKey;
        $this->parentKey = $parentKey;
        $this->relatedKey = $relatedKey;
    }

    public function eagerLoad(array $parents, string $relationName, ?string $nested): void
    {
        if (empty($parents)) return;

        $parentKey = $this->parentKey;
        $idSet = [];
        foreach ($parents as $p) {
            $v = $p->{$parentKey};
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

        // Project the pivot.parent_id alongside related rows so we can
        // fan out the results to each parent without an extra round trip.
        $pivotCol = "{$this->pivotTable}.{$this->foreignPivotKey}";

        $eagerQuery = $this->query->cloneWithoutFirstWhere();
        $eagerQuery->whereIn($pivotCol, array_keys($idSet));
        $eagerQuery->addSelect("{$pivotCol} as nitro_pivot_parent_id");

        $rows = $eagerQuery->get()->all();
        $hydrated = $this->hydrate($rows);

        $grouped = [];
        foreach ($hydrated as $row) {
            $grouped[$row->nitro_pivot_parent_id][] = $row;
        }

        foreach ($parents as $p) {
            $key = $p->{$parentKey};
            $p->setRelation($relationName, new Collection($grouped[$key] ?? []));
        }

        if ($nested && !empty($hydrated)) {
            RelationLoader::load($hydrated, [$nested]);
        }
    }
}
