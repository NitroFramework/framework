<?php

namespace Nitro\Database\Model\Concerns;

use Nitro\Database\Model\BaseModel;
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
                $attributes[$key] = $this->castAttribute($key, $attributes[$key]);
            }
        }

        foreach ($this->hidden as $key) {
            unset($attributes[$key]);
        }

        return $attributes;
    }

    public function toArray(): array
    {
        $array = $this->attributesToArray();

        // Merge loaded relations
        if (property_exists($this, 'relations')) {
            foreach ($this->relations as $key => $value) {
                if ($value instanceof Collection) {
                    $array[$key] = array_map(fn($m) => $m->toArray(), $value->all());
                } elseif ($value instanceof BaseModel) {
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
