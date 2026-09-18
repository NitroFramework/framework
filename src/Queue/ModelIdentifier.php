<?php

namespace Nitro\Queue;

use Nitro\Database\Model\Model;
use Nitro\Support\Collection;

/**
 * Stands in for a model inside a serialized job payload.
 *
 * Holds the class and key rather than the row, so the job re-reads the record
 * as it is when it runs.
 */
final class ModelIdentifier
{
    /**
     * @param class-string<Model>       $class
     * @param mixed|array<int, mixed>   $id         One key, or several for a collection.
     * @param bool                      $collection Whether this stands for several models.
     */
    public function __construct(
        public readonly string $class,
        public readonly mixed $id,
        public readonly bool $collection = false,
    ) {}

    /** Re-read the record, or records, this refers to. */
    public function resolve(): Model|Collection|null
    {
        if (! class_exists($this->class)) {
            return $this->collection ? new Collection() : null;
        }

        if (! $this->collection) {
            return $this->class::query()->find($this->id);
        }

        $ids = is_array($this->id) ? $this->id : [$this->id];

        if ($ids === []) {
            return new Collection();
        }

        $instance = new $this->class();

        return $this->class::query()
            ->whereIn($instance->getKeyName(), $ids)
            ->get();
    }
}
