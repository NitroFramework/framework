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

        if ($this->timestamps) {
            $filtered['updated_at'] = date('Y-m-d H:i:s');
        }

        DB::table($this->getTable())
            ->where($this->primaryKey, $this->getKey())
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
            ->where($this->primaryKey, $this->getKey())
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

            if ($this->timestamps) {
                $dirty['updated_at'] = date('Y-m-d H:i:s');
                $this->attributes['updated_at'] = $dirty['updated_at'];
                unset($this->castCache['updated_at']);
            }

            $affected = DB::table($this->getTable())
                ->where($this->primaryKey, $this->getKey())
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

        if ($this->timestamps) {
            $now = date('Y-m-d H:i:s');
            $attrs['created_at'] = $now;
            $attrs['updated_at'] = $now;
        }

        $id = DB::table($this->getTable())->insertGetId($attrs);
        $this->attributes = $attrs;
        if ($id) {
            $this->attributes[$this->primaryKey] = $id;
        }
        $this->castCache = [];
        $this->syncOriginal();
        $this->exists = true;

        $this->fireModelEvent('created');
        $this->fireModelEvent('saved');

        return true;
    }

    public function refresh(): static
    {
        $row = DB::table($this->getTable())
            ->where($this->primaryKey, $this->getKey())
            ->first();
        if ($row) {
            $this->attributes = (array) $row;
            $this->castCache = [];
            $this->syncOriginal();
        }
        return $this;
    }
}
