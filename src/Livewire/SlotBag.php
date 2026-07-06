<?php

namespace Nitro\Livewire;

use ArrayAccess;

/**
 * The $slots handle exposed to a component's view. Provides has()/get() and
 * array access ($slots['header']) over the named slots passed in by the parent.
 * A missing slot reads as an empty Slot so views never hit an undefined index.
 *
 * @implements ArrayAccess<string, Slot>
 */
class SlotBag implements ArrayAccess
{
    /** @param array<string, Slot> $slots */
    public function __construct(protected array $slots = []) {}

    /** Whether a named slot exists and has content. */
    public function has(string $name): bool
    {
        return isset($this->slots[$name]) && $this->slots[$name]->isNotEmpty();
    }

    /** Get a named slot (an empty Slot when absent). */
    public function get(string $name): Slot
    {
        return $this->slots[$name] ?? new Slot('');
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        // Slots are read-only from the view.
    }

    public function offsetUnset(mixed $offset): void
    {
        // Slots are read-only from the view.
    }
}
