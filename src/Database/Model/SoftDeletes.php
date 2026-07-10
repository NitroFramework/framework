<?php

namespace Nitro\Database\Model;

use Nitro\Database\DB;

/**
 * Soft deletes — Laravel's SoftDeletes trait.
 *
 *   class Post extends Model { use SoftDeletes; }
 *
 * delete() then sets a `deleted_at` timestamp instead of removing the row, and
 * normal queries hide trashed rows (Model::query() adds the global scope).
 * Use withTrashed()/onlyTrashed() to include them, restore() to undo, and
 * forceDelete() to remove permanently. Needs a nullable `deleted_at` column
 * (`$table->softDeletes()` in the migration).
 */
trait SoftDeletes
{
    /** Tells Model::query() to apply the trashed-hiding scope. */
    public function usesSoftDeletes(): bool
    {
        return true;
    }

    public function getDeletedAtColumn(): string
    {
        return 'deleted_at';
    }

    /** Soft delete: stamp deleted_at instead of removing the row. */
    public function delete(): bool
    {
        if (!$this->fireModelEvent('deleting')) {
            return false;
        }

        $column = $this->getDeletedAtColumn();
        $time   = date('Y-m-d H:i:s');

        $affected = DB::table($this->getTable())
            ->where($this->getKeyName(), $this->getKey())
            ->update([$column => $time]);

        $this->attributes[$column] = $time;
        unset($this->castCache[$column]);

        $this->fireModelEvent('deleted');

        return $affected > 0;
    }

    /** Restore a soft-deleted model (clear deleted_at). */
    public function restore(): bool
    {
        if (!$this->fireModelEvent('restoring')) {
            return false;
        }

        $column = $this->getDeletedAtColumn();

        $affected = DB::table($this->getTable())
            ->where($this->getKeyName(), $this->getKey())
            ->update([$column => null]);

        $this->attributes[$column] = null;
        unset($this->castCache[$column]);

        $this->fireModelEvent('restored');

        return $affected > 0;
    }

    /** Permanently remove the row, bypassing the soft-delete behaviour. */
    public function forceDelete(): bool
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

    /** Whether this model is currently soft-deleted. */
    public function trashed(): bool
    {
        return $this->getAttribute($this->getDeletedAtColumn()) !== null;
    }

    /** Query including trashed rows (no soft-delete scope). */
    public static function withTrashed(): ModelBuilder
    {
        $instance = new static;
        return new ModelBuilder(DB::table($instance->getTable()), static::class);
    }

    /** Query ONLY trashed rows. */
    public static function onlyTrashed(): ModelBuilder
    {
        $instance = new static;
        $builder  = new ModelBuilder(DB::table($instance->getTable()), static::class);
        return $builder->whereNotNull($instance->getDeletedAtColumn());
    }
}
