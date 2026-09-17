<?php

namespace Nitro\Database\Model\Concerns;

use Nitro\Database\Model\Model;
use Nitro\Support\Collection;

/**
 * Model concern: array/JSON serialization honoring hidden/visible attributes.
 */
trait SerializesData
{
    public function attributesToArray(): array
    {
        $attributes = $this->attributes;

        foreach ($this->getCasts() as $key => $type) {
            if (isset($attributes[$key])) {
                $attributes[$key] = $this->serializeCastValue(
                    $this->castAttribute($key, $attributes[$key])
                );
            }
        }

        foreach ($this->getAppends() as $key) {
            $attributes[$key] = $this->serializeCastValue($this->getAttribute($key));
        }

        $visible = $this->getVisible();

        if ($visible !== []) {
            $attributes = array_intersect_key($attributes, array_flip($visible));
        }

        foreach ($this->getHidden() as $key) {
            unset($attributes[$key]);
        }

        return $attributes;
    }

    /**
     * Flatten a cast value to something JSON can carry.
     *
     * castAttribute() hands back rich objects — enum cases, DateTimes, whatever a
     * custom caster returns — which is right for application code and wrong for
     * an array destined for json_encode(). An enum serializes as its backing
     * value and a date as an ISO-8601 string, so an API response reads the same
     * as the column it came from; anything that can speak for itself
     * (JsonSerializable, or a value object with toArray()) is left to do so.
     */
    protected function serializeCastValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof \JsonSerializable => $value->jsonSerialize(),
            is_object($value) && method_exists($value, 'toArray') => $value->toArray(),
            default => $value,
        };
    }

    public function toArray(): array
    {
        $array = $this->attributesToArray();

        // Merge loaded relations
        if (property_exists($this, 'relations')) {
            foreach ($this->relations as $key => $value) {
                if ($value instanceof Collection) {
                    $array[$key] = array_map(fn($model) => $model->toArray(), $value->all());
                } elseif ($value instanceof Model) {
                    $array[$key] = $value->toArray();
                } else {
                    $array[$key] = $value;
                }
            }
        }

        return $array;
    }

    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    public function toPrettyJson(int $options = 0): string
    {
        return $this->toJson($options | JSON_PRETTY_PRINT);
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Loaded relations as arrays.
     *
     * @return array<string, mixed>
     */
    public function relationsToArray(): array
    {
        $array = [];

        foreach ($this->getRelations() as $key => $value) {
            $array[$key] = match (true) {
                $value instanceof Collection => array_map(
                    static fn ($model) => $model instanceof Model ? $model->toArray() : $model,
                    $value->all()
                ),
                $value instanceof Model => $value->toArray(),
                default => $value,
            };
        }

        return $array;
    }

    // ─── Visibility ───────────────────────────────────────

    /**
     * Attributes to keep when serializing. An empty list keeps everything that
     * is not hidden.
     *
     * @return array<int, string>
     */
    public function getVisible(): array
    {
        return $this->overrides['visible'] ?? $this->visible ?? [];
    }

    /** @param array<int, string> $visible */
    public function setVisible(array $visible): static
    {
        $this->overrides['visible'] = $visible;

        return $this;
    }

    /** @param array<int, string> $hidden */
    public function setHidden(array $hidden): static
    {
        $this->overrides['hidden'] = $hidden;

        return $this;
    }

    /** @param array<int, string>|string $attributes */
    public function makeHidden(array|string $attributes): static
    {
        $attributes = is_array($attributes) ? $attributes : func_get_args();

        $this->overrides['hidden'] = array_values(array_unique(array_merge($this->getHidden(), $attributes)));

        return $this;
    }

    /** @param array<int, string>|string $attributes */
    public function makeVisible(array|string $attributes): static
    {
        $attributes = is_array($attributes) ? $attributes : func_get_args();

        $this->overrides['hidden'] = array_values(array_diff($this->getHidden(), $attributes));

        if ($this->getVisible() !== []) {
            $this->overrides['visible'] = array_values(array_unique(array_merge($this->getVisible(), $attributes)));
        }

        return $this;
    }

    /** @param array<int, string>|string $attributes */
    public function makeHiddenIf(mixed $condition, array|string $attributes): static
    {
        return $this->resolveCondition($condition) ? $this->makeHidden($attributes) : $this;
    }

    /** @param array<int, string>|string $attributes */
    public function makeVisibleIf(mixed $condition, array|string $attributes): static
    {
        return $this->resolveCondition($condition) ? $this->makeVisible($attributes) : $this;
    }

    /** @param array<int, string>|string $attributes */
    public function mergeHidden(array|string $attributes): static
    {
        return $this->makeHidden($attributes);
    }

    /** @param array<int, string>|string $attributes */
    public function mergeVisible(array|string $attributes): static
    {
        $attributes = is_array($attributes) ? $attributes : func_get_args();

        $this->overrides['visible'] = array_values(array_unique(array_merge($this->getVisible(), $attributes)));

        return $this;
    }

    // ─── Appended accessors ───────────────────────────────

    /**
     * Accessor names added to the serialized output.
     *
     * @return array<int, string>
     */
    public function getAppends(): array
    {
        return $this->overrides['appends'] ?? $this->appends ?? [];
    }

    /** @param array<int, string> $appends */
    public function setAppends(array $appends): static
    {
        $this->overrides['appends'] = $appends;

        return $this;
    }

    /** @param array<int, string>|string $attributes */
    public function append(array|string $attributes): static
    {
        $attributes = is_array($attributes) ? $attributes : func_get_args();

        $this->overrides['appends'] = array_values(array_unique(array_merge($this->getAppends(), $attributes)));

        return $this;
    }

    /** @param array<int, string>|string $attributes */
    public function mergeAppends(array|string $attributes): static
    {
        return $this->append($attributes);
    }

    public function hasAppended(string $attribute): bool
    {
        return in_array($attribute, $this->getAppends(), true);
    }

    /** Serialize without the appended accessors. */
    public function withoutAppends(): static
    {
        $this->overrides['appends'] = [];

        return $this;
    }

    private function resolveCondition(mixed $condition): bool
    {
        return (bool) (is_callable($condition) ? $condition($this) : $condition);
    }
}
