<?php

namespace Nitro\Database\Model\Relations;

use Nitro\Database\DB;
use Nitro\Database\Model\Model;
use Nitro\Database\Model\RelationLoader;
use Nitro\Support\Collection;

/**
 * The child's side of a polymorphic link: this row points at one of several
 * kinds of parent.
 *
 * A sent_emails row is about an enrolment, or a certificate, or an order; it
 * stores related_id and related_type, and the type names which table to look
 * in. That is what makes it different from {@see BelongsTo}, where the target
 * table is fixed and known when the relation is defined: here it is a value in
 * the row, so the relation cannot even build a query until it has read one.
 *
 * Consequences worth knowing:
 *
 *  - It is not a query builder you can constrain. ->where() on a MorphTo has no
 *    single table to apply to. Constrain the parent's own query instead.
 *  - Eager loading is one query per distinct type, not one query. Three types
 *    across a page of results is three queries, which still beats one per row.
 *  - A row whose type names a class that no longer exists resolves to null
 *    rather than throwing. Old rows outlive refactors, and a deleted class
 *    should not take down a list screen that merely mentions it.
 */
class MorphTo extends Relation
{
    protected string $foreignKey;
    protected string $typeColumn;

    public function __construct(
        Model $parent,
        string $foreignKey,
        string $typeColumn,
    ) {
        // A placeholder query: there is no table to aim at until a row's type
        // has been read. resolve() builds the real one.
        parent::__construct($parent, DB::table($parent->getTable()), static::class);

        $this->foreignKey = $foreignKey;
        $this->typeColumn = $typeColumn;
    }

    public function getForeignKey(): string { return $this->foreignKey; }
    public function getTypeColumn(): string { return $this->typeColumn; }

    /** The related model for this parent row, or null. */
    public function first(): ?Model
    {
        $class = $this->resolveClass($this->parent->{$this->typeColumn});
        $id = $this->parent->{$this->foreignKey};

        if ($class === null || $id === null || $id === '') {
            return null;
        }

        return $class::query()->find($id);
    }

    /**
     * The related model, as a collection of nought or one.
     *
     * get() has to keep ModelBuilder's Collection return type, and a morphTo
     * genuinely has at most one result — so first() is the method to reach for.
     * This exists so ->get() does something sensible rather than running the
     * placeholder query against the parent's own table.
     */
    public function get(): Collection
    {
        $related = $this->first();

        return new Collection($related === null ? [] : [$related]);
    }

    /**
     * Eager load, grouped by type: one query per distinct class present, with
     * all of that class's ids in an IN().
     */
    public function eagerLoad(array $parents, string $relationName, ?string $nested): void
    {
        if (empty($parents)) {
            return;
        }

        // Group the ids we need by the class that holds them.
        $byType = [];
        foreach ($parents as $parent) {
            $type = $parent->{$this->typeColumn};
            $id = $parent->{$this->foreignKey};

            if ($type === null || $id === null || $id === '') {
                $parent->setRelation($relationName, null);
                continue;
            }

            $byType[$type][$id] = true;
        }

        $loaded = [];
        foreach ($byType as $type => $ids) {
            $class = $this->resolveClass($type);

            if ($class === null) {
                continue;
            }

            $instance = new $class;

            $rows = $class::query()
                ->whereIn($instance->getKeyName(), array_keys($ids))
                ->get()
                ->all();

            foreach ($rows as $row) {
                $loaded[$type][$row->getKey()] = $row;
            }
        }

        foreach ($parents as $parent) {
            $type = $parent->{$this->typeColumn};
            $id = $parent->{$this->foreignKey};

            $parent->setRelation($relationName, $loaded[$type][$id] ?? null);
        }

        if ($nested) {
            foreach ($loaded as $rows) {
                if ($rows !== []) {
                    RelationLoader::load(array_values($rows), [$nested]);
                }
            }
        }
    }

    /**
     * Turn a stored type into a model class.
     *
     * The value is read through the morph map first, so an application can
     * store 'course' rather than 'App\Models\Course' and rename the class later
     * without rewriting rows. An unknown or deleted class gives null.
     *
     * @return class-string<Model>|null
     */
    protected function resolveClass(mixed $type): ?string
    {
        if (!is_string($type) || $type === '') {
            return null;
        }

        $class = Model::morphedClass($type);

        return (class_exists($class) && is_subclass_of($class, Model::class)) ? $class : null;
    }
}
