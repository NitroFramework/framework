<?php

namespace Nitro\Database\Model\Concerns;

use Nitro\Database\Model\ModelBuilder;
use Nitro\Database\Model\Relations\BelongsTo;
use Nitro\Database\Model\Relations\BelongsToMany;
use Nitro\Database\Model\Relations\HasMany;
use Nitro\Database\Model\Relations\HasManyThrough;
use Nitro\Database\Model\Relations\HasOne;
use Nitro\Database\Model\Relations\MorphMany;
use Nitro\Database\Model\Relations\MorphOne;
use Nitro\Database\Model\Relations\MorphTo;
use Nitro\Support\Str;

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

    // ─── Polymorphic Relationships ────────────────────────

    /**
     * Many children that may belong to several kinds of parent.
     *
     *     $course->comments()  // morphMany(Comment::class, 'commentable')
     *
     * $name is the pair's prefix: 'commentable' means commentable_id and
     * commentable_type on the child's table.
     */
    public function morphMany(string $model, string $name, ?string $type = null, ?string $id = null, ?string $ownerKey = null): MorphMany
    {
        return new MorphMany(
            $this,
            $model,
            $id ?? $name . '_id',
            $type ?? $name . '_type',
            $ownerKey ?? $this->primaryKey,
        );
    }

    /** The singular of {@see morphMany()}. */
    public function morphOne(string $model, string $name, ?string $type = null, ?string $id = null, ?string $ownerKey = null): MorphOne
    {
        return new MorphOne(
            $this,
            $model,
            $id ?? $name . '_id',
            $type ?? $name . '_type',
            $ownerKey ?? $this->primaryKey,
        );
    }

    /**
     * The child's side: this row points at one of several kinds of parent.
     *
     *     $sentEmail->relatedTo()  // morphTo('related')
     *
     * $name defaults to the calling method's name, which is conventionally the
     * relation's name — so morphTo() inside relatedTo() reads related_id and
     * related_type without being told.
     */
    public function morphTo(?string $name = null, ?string $type = null, ?string $id = null): MorphTo
    {
        $name ??= $this->guessMorphName();

        return new MorphTo(
            $this,
            $id ?? $name . '_id',
            $type ?? $name . '_type',
        );
    }

    /** The name of the method that called morphTo(). */
    protected function guessMorphName(): string
    {
        // Frame 0 is this method and frame 1 is morphTo() itself; the caller we
        // want is the first frame that is neither.
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5) as $frame) {
            $function = $frame['function'] ?? '';

            if (isset($frame['class']) && $function !== 'morphTo' && $function !== 'guessMorphName') {
                return $function;
            }
        }

        throw new \LogicException(
            'morphTo() could not work out its name. Pass one: morphTo("related").'
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

    /**
     * The foreign key a related table uses to point at this model.
     *
     * snake_case, not lowercase: CourseVersion means course_version_id, which
     * is what the migration writes and what every other convention in the
     * framework uses. Lowercasing produced courseversion_id and failed at the
     * first query with "no such column".
     */
    protected function guessForeignKey(): string
    {
        return Str::snake(class_basename(static::class)) . '_id';
    }

    protected function guessBelongsToKey(string $model): string
    {
        return Str::snake(class_basename($model)) . '_id';
    }

    protected function guessForeignKeyFor(string $model): string
    {
        return Str::snake(class_basename($model)) . '_id';
    }
}
