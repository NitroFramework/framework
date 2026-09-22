<?php

namespace Nitro\Database\Model\Concerns;

/**
 * Model concern: which attributes may be set in bulk.
 *
 * $fillable is a whitelist and wins outright; $guarded is the blacklist used
 * when there is no whitelist, and its '*' sentinel means nothing is
 * mass-assignable until a whitelist is declared. Both are read through the
 * accessors on Model, so a subclass may type them or not.
 *
 * Separate from HasAttributes because deciding what a caller is allowed to
 * write is not the same job as reading, casting and tracking what is written.
 */
trait GuardsAttributes
{
    /** When true, every model ignores its fillable and guarded lists. */
    protected static bool $unguarded = false;

    public function isFillable(string $key): bool
    {
        if (static::$unguarded) {
            return true;
        }

        $fillable = $this->getFillable();

        if ($fillable !== []) {
            return in_array($key, $fillable, true);
        }

        return ! $this->isGuarded($key);
    }

    public function isGuarded(string $key): bool
    {
        $guarded = $this->getGuarded();

        return $guarded === ['*'] || in_array($key, $guarded, true);
    }

    /** Whether the model guards everything and fills nothing. */
    public function totallyGuarded(): bool
    {
        return $this->getFillable() === [] && $this->getGuarded() === ['*'];
    }

    /** @param array<int, string> $guarded */
    public function guard(array $guarded): static
    {
        $this->overrides['guarded'] = $guarded;

        return $this;
    }

    /** @param array<int, string> $fillable */
    public function fillable(array $fillable): static
    {
        $this->overrides['fillable'] = $fillable;

        return $this;
    }

    /** @param array<int, string> $fillable */
    public function mergeFillable(array $fillable): static
    {
        $this->overrides['fillable'] = array_values(array_unique(array_merge($this->getFillable(), $fillable)));

        return $this;
    }

    /** @param array<int, string> $guarded */
    public function mergeGuarded(array $guarded): static
    {
        $this->overrides['guarded'] = array_values(array_unique(array_merge($this->getGuarded(), $guarded)));

        return $this;
    }

    public static function unguard(bool $state = true): void
    {
        static::$unguarded = $state;
    }

    public static function reguard(): void
    {
        static::$unguarded = false;
    }

    public static function isUnguarded(): bool
    {
        return static::$unguarded;
    }

    /** Run a callback with mass-assignment protection switched off. */
    public static function unguarded(callable $callback): mixed
    {
        if (static::$unguarded) {
            return $callback();
        }

        static::unguard();

        try {
            return $callback();
        } finally {
            static::reguard();
        }
    }



    public function fill(array $attributes): static
    {
        // Route through setAttribute so mutators (setXxxAttribute) run on
        // mass assignment, matching Laravel.
        foreach ($this->filterFillable($attributes) as $key => $value) {
            $this->setAttribute($key, $value);
        }
        return $this;
    }

    protected function filterFillable(array $attributes): array
    {
        // An explicit $fillable whitelist always wins.
        if (!empty($this->getFillable())) {
            return array_intersect_key($attributes, array_flip($this->getFillable()));
        }

        // No whitelist: $guarded is the blacklist. The '*' sentinel means the
        // model is "totally guarded" — nothing is mass-assignable until the
        // developer declares $fillable (Laravel's safe default). Without this
        // the sentinel would only exclude a column literally named '*'.
        if (in_array('*', $this->getGuarded(), true)) {
            return [];
        }

        return array_diff_key($attributes, array_flip($this->getGuarded()));
    }
}
