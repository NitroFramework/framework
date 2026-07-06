<?php

namespace Nitro\Database\Model\Concerns;

use Nitro\Database\Model\ModelBuilder;
use Nitro\Database\Model\Relations\BelongsTo;
use Nitro\Database\Model\Relations\BelongsToMany;
use Nitro\Database\Model\Relations\HasMany;
use Nitro\Database\Model\Relations\HasManyThrough;
use Nitro\Database\Model\Relations\HasOne;

/**
 * Model concern: defining and resolving relationships (hasOne/hasMany/belongsTo/...).
 */
trait HasRelationships
{
    protected array $relations = [];

    // ─── Eager Loading ────────────────────────────────────

    public static function with(string|array $relations): ModelBuilder
    {
        return static::query()->with($relations);
    }

    // ─── Relationship Definitions ─────────────────────────

    public function hasOne(string $model, ?string $foreignKey = null, ?string $ownerKey = null): HasOne
    {
        return new HasOne(
            $this,
            $model,
            $foreignKey ?? $this->guessForeignKey(),
            $ownerKey ?? $this->primaryKey,
        );
    }

    public function hasMany(string $model, ?string $foreignKey = null, ?string $ownerKey = null): HasMany
    {
        return new HasMany(
            $this,
            $model,
            $foreignKey ?? $this->guessForeignKey(),
            $ownerKey ?? $this->primaryKey,
        );
    }

    public function belongsTo(string $model, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        $instance = new $model;
        return new BelongsTo(
            $this,
            $model,
            $foreignKey ?? $this->guessBelongsToKey($model),
            $ownerKey ?? $instance->primaryKey,
        );
    }

    public function belongsToMany(
        string $model,
        string $pivot,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null,
    ): BelongsToMany {
        $instance = new $model;
        return new BelongsToMany(
            $this,
            $model,
            $pivot,
            $foreignPivotKey ?? $this->guessForeignKey(),       // e.g. 'user_id' on pivot
            $relatedPivotKey ?? $this->guessForeignKeyFor($model), // e.g. 'role_id' on pivot
            $parentKey ?? $this->primaryKey,
            $relatedKey ?? $instance->primaryKey,
        );
    }

    public function hasManyThrough(
        string $model,
        string $through,
        ?string $firstKey = null,
        ?string $secondKey = null,
        ?string $localKey = null,
        ?string $secondLocalKey = null,
    ): HasManyThrough {
        $throughInstance = new $through;
        return new HasManyThrough(
            $this,
            $model,
            $throughInstance->getTable(),
            $firstKey ?? $this->guessForeignKey(),                 // FK on intermediate to parent
            $secondKey ?? $this->guessForeignKeyFor($through),     // FK on related to intermediate
            $localKey ?? $this->primaryKey,
            $secondLocalKey ?? $throughInstance->primaryKey,
        );
    }

    // ─── Relation Management ──────────────────────────────

    public function setRelation(string $name, mixed $value): static
    {
        $this->relations[$name] = $value;
        return $this;
    }

    public function getRelation(string $name): mixed
    {
        return $this->relations[$name] ?? null;
    }

    public function hasRelation(string $name): bool
    {
        return array_key_exists($name, $this->relations);
    }

    // ─── Key Guessing ─────────────────────────────────────

    protected function guessForeignKey(): string
    {
        return strtolower(class_basename(static::class)) . '_id';
    }

    protected function guessBelongsToKey(string $model): string
    {
        return strtolower(class_basename($model)) . '_id';
    }

    protected function guessForeignKeyFor(string $model): string
    {
        return strtolower(class_basename($model)) . '_id';
    }
}
