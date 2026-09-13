<?php

namespace Nitro\Database\Model\Relations;

use Nitro\Support\Collection;
use Nitro\Database\DB;
use Nitro\Database\Model\Model;
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
        Model $parent,
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
        foreach ($parents as $parent) {
            $value = $parent->{$parentKey};
            if ($value !== null && $value !== '') {
                $idSet[$value] = true;
            }
        }
        if (empty($idSet)) {
            $empty = new Collection();
            foreach ($parents as $parent) {
                $parent->setRelation($relationName, $empty);
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

        foreach ($parents as $parent) {
            $key = $parent->{$parentKey};
            $parent->setRelation($relationName, new Collection($grouped[$key] ?? []));
        }

        if ($nested && !empty($hydrated)) {
            RelationLoader::load($hydrated, [$nested]);
        }
    }

    // ─── Managing the pivot ───────────────────────────────

    /** Memo for {@see pivotTimestamps()}; null until first asked. */
    protected ?bool $pivotHasTimestamps = null;

    /**
     * Add rows to the pivot.
     *
     *     $course->accreditations()->attach($id);
     *     $course->accreditations()->attach([$a, $b]);
     *     $course->accreditations()->attach($id, ['granted_on' => '2026-01-01']);
     *     $course->accreditations()->attach([$a => ['reference' => 'X'], $b => []]);
     *
     * Attaching something already attached inserts a second row — that is what
     * attach() means. Use syncWithoutDetaching() when it has to be idempotent.
     *
     * @param  mixed  $ids  One id, a list, or a map of id => pivot attributes.
     */
    public function attach(mixed $ids, array $attributes = []): void
    {
        foreach ($this->normaliseIds($ids) as $id => $extra) {
            DB::table($this->pivotTable)->insert(array_merge(
                [
                    $this->foreignPivotKey => $this->parent->{$this->parentKey},
                    $this->relatedPivotKey => $id,
                ],
                $attributes,
                $extra,
                $this->pivotTimestamps(),
            ));
        }
    }

    /**
     * Remove rows from the pivot. With no argument, every row for this parent.
     *
     * @return int  How many rows went.
     */
    public function detach(mixed $ids = null): int
    {
        $query = DB::table($this->pivotTable)
            ->where($this->foreignPivotKey, $this->parent->{$this->parentKey});

        if ($ids !== null) {
            $query->whereIn($this->relatedPivotKey, array_keys($this->normaliseIds($ids)));
        }

        return (int) $query->delete();
    }

    /**
     * Make the pivot match this list exactly: attach what is missing, detach
     * what is no longer wanted, update the pivot data of whatever stays.
     *
     * Returns what actually changed rather than what was asked for, because
     * that is what a caller wants to log or react to.
     *
     * @return array{attached: array<int, mixed>, detached: array<int, mixed>, updated: array<int, mixed>}
     */
    public function sync(mixed $ids, bool $detaching = true): array
    {
        $wanted = $this->normaliseIds($ids);
        $parentValue = $this->parent->{$this->parentKey};

        $current = array_map('strval', (array) DB::table($this->pivotTable)
            ->where($this->foreignPivotKey, $parentValue)
            ->pluck($this->relatedPivotKey));

        $changes = ['attached' => [], 'detached' => [], 'updated' => []];

        if ($detaching) {
            $unwanted = array_values(array_diff($current, array_map('strval', array_keys($wanted))));

            if ($unwanted !== []) {
                $this->detach($unwanted);
                $changes['detached'] = $unwanted;
            }
        }

        foreach ($wanted as $id => $extra) {
            if (! in_array((string) $id, $current, true)) {
                $this->attach([$id => $extra]);
                $changes['attached'][] = $id;

                continue;
            }

            // Already attached: only worth a write when there is pivot data.
            if ($extra !== []) {
                DB::table($this->pivotTable)
                    ->where($this->foreignPivotKey, $parentValue)
                    ->where($this->relatedPivotKey, $id)
                    ->update($extra);

                $changes['updated'][] = $id;
            }
        }

        return $changes;
    }

    /** sync() without removing anything — add and update only. */
    public function syncWithoutDetaching(mixed $ids): array
    {
        return $this->sync($ids, false);
    }

    /**
     * Accept the several shapes an id list arrives in and return one map of
     * id => pivot attributes.
     *
     * @return array<int|string, array<string, mixed>>
     */
    protected function normaliseIds(mixed $ids): array
    {
        if ($ids instanceof Model) {
            return [$ids->getKey() => []];
        }

        if (! is_array($ids)) {
            return [$ids => []];
        }

        $normalised = [];

        foreach ($ids as $key => $value) {
            if (is_array($value)) {
                $normalised[$key] = $value;          // [id => [pivot data]]
            } elseif ($value instanceof Model) {
                $normalised[$value->getKey()] = [];
            } else {
                $normalised[$value] = [];
            }
        }

        return $normalised;
    }

    /**
     * created_at/updated_at for a pivot row, when the table has them.
     *
     * Asked rather than assumed: plenty of pivots are two foreign keys and
     * nothing else, and writing timestamps into a table without the columns
     * would fail every attach.
     *
     * @return array<string, string>
     */
    protected function pivotTimestamps(): array
    {
        if ($this->pivotHasTimestamps === null) {
            $columns = \Nitro\Database\Schema\SchemaBuilder::getColumnListing($this->pivotTable);

            $this->pivotHasTimestamps = in_array('created_at', $columns, true)
                && in_array('updated_at', $columns, true);
        }

        if (! $this->pivotHasTimestamps) {
            return [];
        }

        $now = date('Y-m-d H:i:s');

        return ['created_at' => $now, 'updated_at' => $now];
    }
}
