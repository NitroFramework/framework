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

        foreach ($this->casts as $key => $type) {
            if (isset($attributes[$key])) {
                $attributes[$key] = $this->serializeCastValue(
                    $this->castAttribute($key, $attributes[$key])
                );
            }
        }

        foreach ($this->hidden as $key) {
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
}
