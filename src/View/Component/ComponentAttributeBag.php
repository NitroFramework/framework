<?php

namespace Nitro\View\Component;

use Nitro\View\Support\Htmlable;

/**
 * A bag of HTML attributes passed to a component, with merge/filter helpers.
 */
class ComponentAttributeBag implements Htmlable, \Stringable
{
    public function __construct(private array $attributes = []) {}

    public function toHtml(): string
    {
        return $this->__toString();
    }
    /**
     * Merge given defaults with current attributes.
     * Classes are concatenated, everything else is overridden by the attribute.
     */
    public function merge(array $defaults = []): self
    {
        $merged = $defaults;

        foreach ($this->attributes as $key => $value) {
            if ($key === 'class' && isset($defaults['class'])) {
                $merged['class'] = trim($defaults['class'] . ' ' . $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return new self($merged);
    }

    /**
     * Return a new bag with only the given keys.
     */
    public function only(array $keys): self
    {
        return new self(array_intersect_key($this->attributes, array_flip($keys)));
    }

    /**
     * Return a new bag without the given keys.
     */
    public function except(array $keys): self
    {
        return new self(array_diff_key($this->attributes, array_flip($keys)));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->attributes;
    }

    /**
     * Add/override a single attribute and return a new bag.
     */
    public function with(string $key, mixed $value): self
    {
        return new self(array_merge($this->attributes, [$key => $value]));
    }

    /**
     * Render all attributes as an HTML string.
     *
     * true  → attribute name only (e.g. disabled)
     * false → attribute omitted entirely
     * null  → attribute omitted entirely
     */
    public function __toString(): string
    {
        $parts = [];

        foreach ($this->attributes as $key => $value) {
            if ($value === false || $value === null) {
                continue;
            }

            if ($value === true) {
                $parts[] = htmlspecialchars((string) $key, ENT_QUOTES);
            } else {
                $parts[] = htmlspecialchars((string) $key, ENT_QUOTES)
                    . '="'
                    . htmlspecialchars((string) $value, ENT_QUOTES)
                    . '"';
            }
        }

        return implode(' ', $parts);
    }
}
