<?php

namespace Nitro\Database\Model\Concerns;

use Nitro\Database\DB;

/**
 * Model concern: create/read/update/delete persistence operations.
 */
trait HasCrud
{
    // ─── Static CRUD ──────────────────────────────────────

    /**
     * Mass-assign and persist a new model. Routes through save() so model
     * events (saving/creating/created/saved) fire.
     */
    public static function create(array $attributes): static
    {
        $model = new static;
        $model->fill($attributes);
        $model->save();
        return $model;
    }

    // ─── Instance CRUD ────────────────────────────────────

    /**
     * Persist the given (fillable) attributes directly — Laravel's update().
     * Writes the supplied columns (not a dirty diff), so it works on a
     * freshly-loaded model without first snapshotting original state. Fires
     * saving/updating/updated/saved.
     */
    public function update(array $attributes): bool
    {
        $filtered = $this->filterFillable($attributes);

        if (!$this->fireModelEvent('saving') || !$this->fireModelEvent('updating')) {
            return false;
        }

        if ($this->usesTimestamps()) {
            $filtered['updated_at'] = date('Y-m-d H:i:s');
        }

        DB::table($this->getTable())
            ->where($this->getKeyName(), $this->getKey())
            ->update($filtered);

        // Apply the written columns to the in-memory model regardless of the
        // affected-row count: a no-op write (unchanged values) reports 0
        // affected rows on MySQL but still succeeded, so the model must reflect
        // them and update() must not report failure. Matches Eloquent.
        foreach ($filtered as $key => $value) {
            $this->attributes[$key] = $value;
            unset($this->castCache[$key]);
        }
        $this->syncOriginal();

        $this->fireModelEvent('updated');
        $this->fireModelEvent('saved');

        return true;
    }

    public function delete(): bool
    {
        if (!$this->fireModelEvent('deleting')) {
            return false;
        }

        $affected = DB::table($this->getTable())
            ->where($this->getKeyName(), $this->getKey())
            ->delete();

        $this->exists = false;

        $this->fireModelEvent('deleted');

        return $affected > 0;
    }

    public function save(): bool
    {
        if (!$this->fireModelEvent('saving')) {
            return false;
        }

        // ─── Update path ───────────────────────────────────
        if ($this->exists) {
            $dirty = $this->getDirty();
            if (empty($dirty)) {
                return true;
            }

            if (!$this->fireModelEvent('updating')) {
                return false;
            }

            if ($this->usesTimestamps()) {
                $dirty['updated_at'] = date('Y-m-d H:i:s');
                $this->attributes['updated_at'] = $dirty['updated_at'];
                unset($this->castCache['updated_at']);
            }

            $affected = DB::table($this->getTable())
                ->where($this->getKeyName(), $this->getKey())
                ->update($dirty);

            $this->syncOriginal();
            $this->fireModelEvent('updated');
            $this->fireModelEvent('saved');

            return $affected > 0 || empty($dirty);
        }

        // ─── Insert path ───────────────────────────────────
        if (!$this->fireModelEvent('creating')) {
            return false;
        }

        // Bypass filterFillable: save() honors whatever attributes were
        // assigned directly. Mass-assignment filtering belongs to create()/fill().
        $attrs = $this->attributes;

        if ($this->usesTimestamps()) {
            $now = date('Y-m-d H:i:s');
            $attrs['created_at'] = $now;
            $attrs['updated_at'] = $now;
        }

        $id = DB::table($this->getTable())->insertGetId($attrs);
        $this->attributes = $attrs;
        if ($id) {
            $this->attributes[$this->getKeyName()] = $id;
        }
        $this->castCache = [];
        $this->syncOriginal();
        $this->exists = true;

        $this->fireModelEvent('created');
        $this->fireModelEvent('saved');

        return true;
    }

    // ─── Quiet and failing writes ─────────────────────────

    /** Save without firing model events. */
    public function saveQuietly(): bool
    {
        return static::withoutEvents(fn (): bool => $this->save());
    }

    /** @param array<string, mixed> $attributes */
    public function updateQuietly(array $attributes = []): bool
    {
        return static::withoutEvents(fn (): bool => $this->update($attributes));
    }

    public function deleteQuietly(): bool
    {
        return static::withoutEvents(fn (): bool => (bool) $this->delete());
    }

    /** @throws \RuntimeException When the write does not succeed. */
    public function saveOrFail(): bool
    {
        if (! $this->save()) {
            throw new \RuntimeException('Failed to save [' . static::class . '].');
        }

        return true;
    }

    /**
     * @param  array<string, mixed> $attributes
     * @throws \RuntimeException When the write does not succeed.
     */
    public function updateOrFail(array $attributes = []): bool
    {
        if (! $this->update($attributes)) {
            throw new \RuntimeException('Failed to update [' . static::class . '].');
        }

        return true;
    }

    /** @throws \RuntimeException When the delete does not succeed. */
    public function deleteOrFail(): bool
    {
        if (! $this->delete()) {
            throw new \RuntimeException('Failed to delete [' . static::class . '].');
        }

        return true;
    }

    /** Save the model and every loaded relation beneath it. */
    public function push(): bool
    {
        if (! $this->save()) {
            return false;
        }

        foreach ($this->getRelations() as $related) {
            $models = $related instanceof \Nitro\Support\Collection
                ? $related->all()
                : [$related];

            foreach ($models as $model) {
                if ($model instanceof self && ! $model->push()) {
                    return false;
                }
            }
        }

        return true;
    }

    public function pushQuietly(): bool
    {
        return static::withoutEvents(fn (): bool => $this->push());
    }

    /**
     * Delete records by key.
     *
     * @param  array<int, mixed>|mixed $ids
     * @return int Rows deleted.
     */
    public static function destroy(mixed $ids): int
    {
        $ids = is_array($ids) ? $ids : func_get_args();

        if ($ids === []) {
            return 0;
        }

        $instance = new static();
        $deleted = 0;

        foreach ($instance->newQuery()->whereIn($instance->getKeyName(), $ids)->get() as $model) {
            if ($model->delete()) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * An unsaved copy of the model, without its key or timestamps.
     *
     * @param array<int, string>|null $except
     */
    public function replicate(?array $except = null): static
    {
        $skip = array_values(array_filter([
            $this->getKeyName(),
            $this->getCreatedAtColumn(),
            $this->getUpdatedAtColumn(),
        ]));

        $skip = array_merge($skip, $except ?? []);

        $attributes = array_diff_key($this->attributes, array_flip($skip));

        $copy = new static();
        $copy->setRawAttributes($attributes);
        $copy->setRelations($this->getRelations());
        $copy->exists = false;

        return $copy;
    }

    public function replicateQuietly(?array $except = null): static
    {
        return static::withoutEvents(fn (): static => $this->replicate($except));
    }

    public function refresh(): static
    {
        $row = DB::table($this->getTable())
            ->where($this->getKeyName(), $this->getKey())
            ->first();
        if ($row) {
            $this->attributes = (array) $row;
            $this->castCache = [];
            $this->syncOriginal();
        }
        return $this;
    }
}
