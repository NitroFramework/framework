<?php

namespace Nitro\Database\Model\Concerns;

/**
 * Model concern: which attributes are allowed to appear in array and JSON form.
 *
 * $hidden removes attributes from the output; $visible, when set, is the only
 * list that appears. Both are read through the accessors on Model, so a
 * subclass may type them or not, and both can be adjusted per instance —
 * makeVisible() on a model about to be serialised for an admin, say.
 *
 * Separate from SerializesData because choosing what may be shown is not the
 * same job as turning the chosen set into an array.
 */
trait HidesAttributes
{
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
}
