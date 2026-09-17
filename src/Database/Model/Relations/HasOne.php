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

    /**
     * Narrow this to the one related row that wins an aggregate.
     *
     *     $this->hasOne(CourseVersion::class)->ofMany(
     *         ['version' => 'max'],
     *         fn ($query) => $query->where('status', VersionStatus::Published),
     *     );
     *
     * The closure constrains what is eligible to win, inside the aggregate —
     * see {@see HasOneOfMany} for why that distinction is not cosmetic.
     *
     * @param  array<string, string>  $aggregate  Column => 'max'|'min'.
     */
    public function ofMany(array $aggregate, ?\Closure $constraint = null): HasOneOfMany
    {
        return new HasOneOfMany(
            $this->parent,
            $this->modelClass,
            $this->foreignKey,
            $this->ownerKey,
            $aggregate,
            $constraint,
        );
    }

    /** The related row with the highest $column. Shorthand for ofMany([$column => 'max']). */
    public function latestOfMany(string $column = 'id', ?\Closure $constraint = null): HasOneOfMany
    {
        return $this->ofMany([$column => 'max'], $constraint);
    }

    /** The related row with the lowest $column. Shorthand for ofMany([$column => 'min']). */
    public function oldestOfMany(string $column = 'id', ?\Closure $constraint = null): HasOneOfMany
    {
        return $this->ofMany([$column => 'min'], $constraint);
    }

    public function eagerLoad(array $parents, string $relationName, ?string $nested): void
    {
        if (empty($parents)) return;

        $owner = $this->ownerKey;
        $idSet = [];
        foreach ($parents as $parent) {
            $value = $parent->{$owner};
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

        // withoutLimit(): the constructor's limit(1) is right for one parent and
        // wrong for a batched lookup — kept, it would return a single row for
        // the whole set and hand every other parent a null relation.
        $eagerQuery = $this->query->cloneWithoutFirstWhere()->withoutLimit();
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

        foreach ($parents as $parent) {
            $parent->setRelation($relationName, $indexed[$parent->{$owner}] ?? null);
        }

        if ($nested && !empty($hydrated)) {
            RelationLoader::load($hydrated, [$nested]);
        }
    }

    /** Single-result relation: one model or null. Inherited by MorphOne and HasOneOfMany. */
    public function getResults(): mixed
    {
        return $this->first();
    }
}
