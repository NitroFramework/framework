<?php

namespace Nitro\Database\Model\Relations;

use Closure;
use Nitro\Database\Model\Model;
use Nitro\Database\Model\RelationLoader;

/**
 * One parent → the one child that wins an aggregate.
 *
 *     $this->hasOne(CourseVersion::class)->ofMany(
 *         ['version' => 'max'],
 *         fn ($query) => $query->where('status', VersionStatus::Published),
 *     );
 *
 * The constraint belongs INSIDE the aggregate, and that is the whole reason
 * this class exists rather than an ordering plus limit(1). Written the naive
 * way — take MAX(version) across every row, then filter the result by status —
 * the aggregate picks the highest version regardless of status, and the filter
 * then matches nothing the moment somebody starts a draft revision. The course
 * silently has no live version at all: its public page blanks and new
 * enrolments stop, on the day an author opened an unrelated draft.
 *
 * So the constraint is applied to the aggregate query and to the outer query
 * both, and "highest version that is published" is asked as one question.
 */
class HasOneOfMany extends HasOne
{
    /**
     * Column => aggregate function ('max' or 'min'). One pair; a tie-break on a
     * second column is not supported, so pick a column that is unique per
     * parent (a version number, an id, a timestamp you control).
     *
     * @var array<string, string>
     */
    protected array $aggregate;

    /** The constraint that narrows what is eligible to win, if any. */
    protected ?Closure $constraint;

    /**
     * @param  array<string, string>  $aggregate
     */
    public function __construct(
        Model $parent,
        string $relatedClass,
        string $foreignKey,
        string $ownerKey,
        array $aggregate,
        ?Closure $constraint = null,
    ) {
        parent::__construct($parent, $relatedClass, $foreignKey, $ownerKey);

        $this->aggregate = $aggregate;
        $this->constraint = $constraint;

        [$column, $function] = $this->aggregateParts();

        // The single-parent case needs no subquery: constrain, order by the
        // aggregate column, and the limit(1) HasOne already set takes the winner.
        if ($constraint !== null) {
            $constraint($this);
        }

        $this->orderBy($column, $function === 'min' ? 'asc' : 'desc');
    }

    /** @return array{0: string, 1: string} The aggregate column and function. */
    protected function aggregateParts(): array
    {
        $column = array_key_first($this->aggregate);

        return [$column, strtolower($this->aggregate[$column])];
    }

    /**
     * Eager load across many parents.
     *
     * Two queries, and no derived table — a join against a subquery is the
     * usual shape, but it has to be spelled differently for every grammar and
     * this has to hold on both SQLite and MySQL.
     *
     *   1. Ask for the winning aggregate value per parent, with the constraint
     *      applied, grouped by the foreign key.
     *   2. Fetch the rows that could match, then keep the one whose aggregate
     *      value equals its parent's winner.
     *
     * Step 2 can over-fetch when two parents' winning values collide, which is
     * why the pairing is re-checked in PHP rather than trusted from the IN().
     */
    public function eagerLoad(array $parents, string $relationName, ?string $nested): void
    {
        if (empty($parents)) {
            return;
        }

        [$column, $function] = $this->aggregateParts();
        $owner = $this->ownerKey;

        $ids = [];
        foreach ($parents as $parent) {
            $value = $parent->{$owner};
            if ($value !== null && $value !== '') {
                $ids[$value] = true;
            }
        }

        if (empty($ids)) {
            foreach ($parents as $parent) {
                $parent->setRelation($relationName, null);
            }
            return;
        }

        $ids = array_keys($ids);
        $fkShort = $this->shortForeignKey();

        // 1. The winning aggregate value per parent.
        $winners = [];
        $aggregateQuery = $this->constrainedQuery()
            ->selectRaw($this->quote($this->foreignKey) . ' as ' . $this->quote('nitro_parent'))
            ->selectRaw($function . '(' . $this->quote($column) . ') as ' . $this->quote('nitro_winner'))
            ->whereIn($this->foreignKey, $ids)
            ->groupBy($this->foreignKey);

        foreach ($aggregateQuery->get()->all() as $row) {
            $winners[$row->nitro_parent] = $row->nitro_winner;
        }

        if (empty($winners)) {
            foreach ($parents as $parent) {
                $parent->setRelation($relationName, null);
            }
            return;
        }

        // 2. The rows that could be those winners.
        $rows = $this->constrainedQuery()
            ->whereIn($this->foreignKey, array_keys($winners))
            ->whereIn($column, array_values(array_unique($winners)))
            ->get()
            ->all();

        $indexed = [];
        foreach ($this->hydrate($rows) as $row) {
            $parentId = $row->{$fkShort};

            // The pair has to match: a row can carry another parent's winning
            // value and still not be this parent's winner.
            if (!isset($winners[$parentId]) || $row->{$column} != $winners[$parentId]) {
                continue;
            }

            $indexed[$parentId] ??= $row;
        }

        foreach ($parents as $parent) {
            $parent->setRelation($relationName, $indexed[$parent->{$owner}] ?? null);
        }

        if ($nested && !empty($indexed)) {
            RelationLoader::load(array_values($indexed), [$nested]);
        }
    }

    /**
     * A fresh query over the related table with only the relation's constraint
     * on it.
     *
     * Built from the table rather than cloned from $this->query, which carries
     * three things that are right for one parent and wrong for a batch: the
     * per-parent WHERE, the limit(1), and the ORDER BY the constructor added to
     * make limit(1) pick the winner. The aggregate does the choosing here.
     */
    protected function constrainedQuery()
    {
        $instance = new $this->modelClass;

        $query = \Nitro\Database\DB::table($instance->getTable());

        if ($this->constraint !== null) {
            ($this->constraint)($query);
        }

        return $query;
    }

    /** The foreign key without its table qualifier, for reading off a row. */
    protected function shortForeignKey(): string
    {
        return str_contains($this->foreignKey, '.')
            ? substr($this->foreignKey, strrpos($this->foreignKey, '.') + 1)
            : $this->foreignKey;
    }

    /** Identifier quoting for the two raw aggregate fragments above. */
    protected function quote(string $identifier): string
    {
        return $this->query->getGrammar()->wrap($identifier);
    }
}
