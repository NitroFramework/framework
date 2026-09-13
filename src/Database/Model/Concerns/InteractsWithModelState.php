<?php

namespace Nitro\Database\Model\Concerns;

use Nitro\Database\Model\RelationLoader;

/**
 * Reading and writing a model's own state outside the normal fill/save path:
 * bypassing mass-assignment, reloading from the database, and loading
 * relations after the fact.
 */
trait InteractsWithModelState
{
    /**
     * Set attributes without consulting $fillable or $guarded.
     *
     * Mass-assignment protection exists to stop request data reaching columns
     * it should not. Code that has already decided what to write — an action,
     * a listener, a migration-time backfill — is not request data, and making
     * every such column fillable to satisfy the guard would weaken it exactly
     * where it matters.
     *
     * Mutators and casts still run: this bypasses the whitelist, not the model.
     */
    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    /**
     * A new instance of this row, read fresh from the database.
     *
     * Distinct from refresh(), which reloads this object in place. fresh()
     * leaves the original untouched, which is what you want when comparing
     * before and after, or when the current instance holds unsaved changes you
     * are not ready to discard.
     */
    public function fresh(array|string $with = []): ?static
    {
        if (! $this->exists) {
            return null;
        }

        $query = static::query()->where($this->getKeyName(), $this->getKey());

        if ($with !== []) {
            $query->with($with);
        }

        return $query->first();
    }

    /** Whether a relation has already been loaded onto this instance. */
    public function relationLoaded(string $relation): bool
    {
        return $this->hasRelation($relation);
    }

    /**
     * Load relations onto this model now.
     *
     * For when the decision to need a relation is made after the query — a
     * notification that has to reach through to a course, say. Reloads a
     * relation that is already present.
     *
     * @param  array<int|string, mixed>|string  $relations
     */
    public function load(array|string $relations): static
    {
        $models = [$this];

        RelationLoader::load($models, (array) $relations);

        return $this;
    }

    /**
     * Load only the relations that are not already loaded.
     *
     * The difference matters in a loop: load() inside one re-queries on every
     * iteration, which is the N+1 the eager load was supposed to prevent.
     *
     * @param  array<int|string, mixed>|string  $relations
     */
    public function loadMissing(array|string $relations): static
    {
        $wanted = [];

        foreach ((array) $relations as $relation) {
            // A nested path is considered present only if its root is; the
            // loader sorts out the rest.
            $root = explode('.', (string) $relation, 2)[0];

            if (! $this->relationLoaded($root)) {
                $wanted[] = $relation;
            }
        }

        return $wanted === [] ? $this : $this->load($wanted);
    }
}
