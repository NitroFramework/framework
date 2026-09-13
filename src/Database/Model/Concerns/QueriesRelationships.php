<?php

namespace Nitro\Database\Model\Concerns;

use Closure;
use Nitro\Database\Model\Relations\BelongsTo;
use Nitro\Database\Model\Relations\HasMany;
use Nitro\Database\Model\Relations\HasOne;
use Nitro\Database\Model\Relations\MorphMany;
use Nitro\Database\Model\Relations\MorphOne;
use Nitro\Database\Model\Relations\Relation;
use Nitro\Database\Query\QueryBuilder;
use Nitro\Database\Query\RawExpression;

/**
 * Constraining and counting a query by what its relations contain.
 *
 *     Course::query()->whereHas('versions', fn ($q) => $q->where('status', 'published'))
 *     Category::query()->withCount(['courses' => fn ($q) => $q->sellable()])
 *
 * Both compile to a correlated subquery rather than a join, which is what
 * keeps them composable: two whereHas() calls do not multiply rows the way two
 * joins would, and a count does not have to fight a GROUP BY that some other
 * part of the query added.
 *
 * Supported for hasOne, hasMany, belongsTo and their morph variants. A
 * belongsToMany or hasManyThrough needs a second hop, and rather than emit
 * something that looks right and is not, those throw.
 */
trait QueriesRelationships
{
    /**
     * Only rows whose relation has at least one match.
     *
     * @param  Closure|null  $constraint  Receives the subquery.
     */
    public function whereHas(string $relation, ?Closure $constraint = null): static
    {
        return $this->addRelationExistence($relation, $constraint, 'AND', false);
    }

    /** Only rows whose relation has no match. */
    public function whereDoesntHave(string $relation, ?Closure $constraint = null): static
    {
        return $this->addRelationExistence($relation, $constraint, 'AND', true);
    }

    public function orWhereHas(string $relation, ?Closure $constraint = null): static
    {
        return $this->addRelationExistence($relation, $constraint, 'OR', false);
    }

    /** Only rows whose relation has at least one match, unconstrained. */
    public function has(string $relation): static
    {
        return $this->whereHas($relation);
    }

    public function doesntHave(string $relation): static
    {
        return $this->whereDoesntHave($relation);
    }

    /**
     * Add a `{relation}_count` column, optionally constrained.
     *
     *     ->withCount('lessons')
     *     ->withCount(['courses' => fn ($q) => $q->sellable()])
     *     ->withCount(['courses as live_count' => fn ($q) => $q->sellable()])
     *
     * @param  string|array<int|string, string|Closure>  $relations
     */
    public function withCount(string|array $relations): static
    {
        foreach ($this->normaliseCountRelations($relations) as [$relation, $alias, $constraint]) {
            $this->addRelationCount($relation, $alias, $constraint);
        }

        return $this;
    }

    // ─── Compilation ──────────────────────────────────────

    protected function addRelationExistence(string $relation, ?Closure $constraint, string $boolean, bool $not): static
    {
        [$table, $correlate, $scope] = $this->relationSubqueryParts($relation);

        $related = $this->relatedClassFor($relation);

        $this->query->whereExists(function (QueryBuilder $sub) use ($table, $correlate, $scope, $constraint, $related) {
            // select(), not selectRaw(): columns default to ['*'], and
            // appending would leave the subquery returning every column, which
            // SQL rejects for EXISTS with "sub-select returns 5 columns".
            $sub->from($table)->select(new RawExpression('1'));

            $correlate($sub);
            $scope($sub);

            $this->applyConstraint($constraint, $sub, $related);
        }, $boolean, $not);

        return $this;
    }

    protected function addRelationCount(string $relation, string $alias, ?Closure $constraint): static
    {
        [$table, $correlate, $scope] = $this->relationSubqueryParts($relation);

        $sub = new QueryBuilder($this->query->getConnection(), $this->query->getGrammar());
        $sub->from($table)->select(new RawExpression('count(*)'));

        $correlate($sub);
        $scope($sub);

        $this->applyConstraint($constraint, $sub, $this->relatedClassFor($relation));

        $grammar = $this->query->getGrammar();

        // The model's own columns have to be named explicitly: adding a
        // computed column would otherwise replace the implicit `*` and the
        // query would return nothing but the count.
        if ($this->query->getColumns() === []) {
            $this->query->select($this->parentTable() . '.*');
        }

        $this->query->selectRaw(
            '(' . $sub->toSql() . ') as ' . $grammar->wrap($alias),
            $sub->getBindings()
        );

        return $this;
    }

    /**
     * The pieces a subquery over $relation needs: the related table, a closure
     * correlating it to the outer row, and a closure applying whatever the
     * relation itself constrains (a morph type).
     *
     * @return array{0: string, 1: Closure, 2: Closure}
     */
    protected function relationSubqueryParts(string $relation): array
    {
        $parent = new $this->modelClass;

        if (! method_exists($parent, $relation)) {
            throw new \InvalidArgumentException(
                static::class . ": [{$this->modelClass}] has no relation called [{$relation}]."
            );
        }

        $instance = $parent->{$relation}();

        if (! $instance instanceof Relation) {
            throw new \InvalidArgumentException(
                "[{$this->modelClass}::{$relation}()] did not return a relation."
            );
        }

        $relatedTable = (new ($instance->getModelClass()))->getTable();
        $parentTable = $this->parentTable();

        // Morph first: MorphMany extends HasMany, so testing HasMany before it
        // would silently drop the type constraint and count another kind of
        // parent's rows as this one's.
        if ($instance instanceof MorphMany || $instance instanceof MorphOne) {
            $type = $instance->getTypeColumn();
            $morphClass = $parent->getMorphClass();

            return [
                $relatedTable,
                fn (QueryBuilder $sub) => $sub->whereColumn(
                    $relatedTable . '.' . $instance->getForeignKey(),
                    '=',
                    $parentTable . '.' . $instance->getOwnerKey(),
                ),
                fn (QueryBuilder $sub) => $sub->where($relatedTable . '.' . $type, $morphClass),
            ];
        }

        if ($instance instanceof HasMany || $instance instanceof HasOne) {
            return [
                $relatedTable,
                fn (QueryBuilder $sub) => $sub->whereColumn(
                    $relatedTable . '.' . $instance->getForeignKey(),
                    '=',
                    $parentTable . '.' . $instance->getOwnerKey(),
                ),
                static fn (QueryBuilder $sub) => null,
            ];
        }

        if ($instance instanceof BelongsTo) {
            // Reversed: the key lives on the parent and points at the related
            // row's own key.
            return [
                $relatedTable,
                fn (QueryBuilder $sub) => $sub->whereColumn(
                    $relatedTable . '.' . $instance->getOwnerKey(),
                    '=',
                    $parentTable . '.' . $instance->getForeignKey(),
                ),
                static fn (QueryBuilder $sub) => null,
            ];
        }

        throw new \InvalidArgumentException(
            'whereHas()/withCount() do not support ' . $instance::class
            . ' yet. Constrain the query directly instead of emitting a subquery that looks right and is not.'
        );
    }

    protected function parentTable(): string
    {
        return (new $this->modelClass)->getTable();
    }

    /**
     * Hand the constraint a ModelBuilder over the subquery, not the raw query.
     *
     * That is what lets a constraint use the related model's own vocabulary —
     * a local scope, or a nested whereHas — instead of only plain wheres.
     * `withCount(['courses' => fn ($q) => $q->sellable()])` is the ordinary way
     * to write this, and $q has to understand sellable() for it to work.
     *
     * The ModelBuilder wraps the same QueryBuilder instance, so everything it
     * applies lands on the subquery being built.
     */
    protected function applyConstraint(?Closure $constraint, QueryBuilder $sub, string $relatedClass): void
    {
        if ($constraint === null) {
            return;
        }

        $constraint(new \Nitro\Database\Model\ModelBuilder($sub, $relatedClass));
    }

    /** @return class-string */
    protected function relatedClassFor(string $relation): string
    {
        return (new $this->modelClass)->{$relation}()->getModelClass();
    }

    /**
     * Normalise the several shapes withCount() accepts into
     * [relation, alias, constraint] triples.
     *
     * @param  string|array<int|string, string|Closure>  $relations
     * @return array<int, array{0: string, 1: string, 2: Closure|null}>
     */
    protected function normaliseCountRelations(string|array $relations): array
    {
        $normalised = [];

        foreach ((array) $relations as $key => $value) {
            [$name, $constraint] = is_string($key)
                ? [$key, ($value instanceof Closure ? $value : null)]
                : [$value, null];

            // 'courses as live_count' names the column itself.
            if (is_string($name) && stripos($name, ' as ') !== false) {
                [$relation, $alias] = preg_split('/\s+as\s+/i', $name, 2);
            } else {
                $relation = (string) $name;
                $alias = $relation . '_count';
            }

            $normalised[] = [trim($relation), trim($alias), $constraint];
        }

        return $normalised;
    }
}
