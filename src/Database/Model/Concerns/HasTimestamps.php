<?php

namespace Nitro\Database\Model\Concerns;

use Nitro\Support\Carbon;

/**
 * Model concern: the created/updated columns and the format they are stored in.
 *
 * Separate from HasCrud because keeping time on a row is not the same job as
 * writing the row. HasCrud carried both, which is how a date-format setting
 * ended up buried among thirty persistence methods and went years without
 * anyone noticing it was never wired to anything.
 *
 * A model opts out with `protected $timestamps = false`, one call out with
 * withoutTimestamps(), or a set of classes out with withoutTimestampsOn().
 */
trait HasTimestamps
{
    /** Models currently ignoring timestamps, keyed by class name. */
    protected static array $ignoreTimestampsOn = [];

    public function getCreatedAtColumn(): ?string
    {
        return static::CREATED_AT;
    }

    public function getUpdatedAtColumn(): ?string
    {
        return static::UPDATED_AT;
    }

    public function getQualifiedCreatedAtColumn(): ?string
    {
        $column = $this->getCreatedAtColumn();

        return $column === null ? null : $this->qualifyColumn($column);
    }

    public function getQualifiedUpdatedAtColumn(): ?string
    {
        $column = $this->getUpdatedAtColumn();

        return $column === null ? null : $this->qualifyColumn($column);
    }

    /** A fresh timestamp in the format the database columns use. */
    public function freshTimestamp(): Carbon
    {
        return Carbon::now();
    }

    public function freshTimestampString(): string
    {
        return $this->freshTimestamp()->format($this->getDateFormat());
    }

    /** The format date columns are stored in: an override, the subclass's, or the default. */
    public function getDateFormat(): string
    {
        return $this->overrides['dateFormat'] ?? $this->dateFormat ?? 'Y-m-d H:i:s';
    }

    /**
     * Set the storage format at runtime.
     *
     * Through $overrides, like every other setter on the model, and for the
     * reason the class docblock gives: $dateFormat is subclass configuration
     * and is deliberately not declared, so assigning it here would not set a
     * field — Model::__set() forwards an undeclared property to
     * setAttribute(), staging a database column named `dateFormat` for the
     * next save().
     */
    public function setDateFormat(string $format): static
    {
        $this->overrides['dateFormat'] = $format;

        return $this;
    }

    public function setCreatedAt(mixed $value): static
    {
        $column = $this->getCreatedAtColumn();

        if ($column !== null) {
            $this->setAttribute($column, $value);
        }

        return $this;
    }

    public function setUpdatedAt(mixed $value): static
    {
        $column = $this->getUpdatedAtColumn();

        if ($column !== null) {
            $this->setAttribute($column, $value);
        }

        return $this;
    }

    /** Set the created/updated columns to now, without saving. */
    public function updateTimestamps(): static
    {
        if (! $this->usesTimestamps()) {
            return $this;
        }

        $now = $this->freshTimestampString();

        $this->setUpdatedAt($now);

        if (! $this->exists) {
            $this->setCreatedAt($now);
        }

        return $this;
    }

    public function isIgnoringTimestamps(): bool
    {
        return isset(static::$ignoreTimestampsOn[static::class])
            || isset(static::$ignoreTimestampsOn['*']);
    }

    /** Run a callback with this model's timestamps switched off. */
    public static function withoutTimestamps(callable $callback): mixed
    {
        return static::withoutTimestampsOn([static::class], $callback);
    }

    /**
     * Run a callback with timestamps switched off for the named models.
     *
     * @param array<int, class-string> $models
     */
    public static function withoutTimestampsOn(array $models, callable $callback): mixed
    {
        foreach ($models as $model) {
            static::$ignoreTimestampsOn[$model] = true;
        }

        try {
            return $callback();
        } finally {
            foreach ($models as $model) {
                unset(static::$ignoreTimestampsOn[$model]);
            }
        }
    }

    /** Set the updated timestamp and save. */
    public function touch(?string $attribute = null): bool
    {
        if ($attribute !== null) {
            $this->setAttribute($attribute, $this->freshTimestampString());

            return $this->save();
        }

        if (! $this->usesTimestamps()) {
            return false;
        }

        $this->updateTimestamps();

        return $this->save();
    }

    public function touchQuietly(?string $attribute = null): bool
    {
        return static::withoutEvents(fn (): bool => $this->touch($attribute));
    }
}
