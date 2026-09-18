<?php

namespace Nitro\View\Contracts;

/**
 * Renders Blade components and tracks the open component and slot stacks.
 */
interface ComponentEngine
{
    /**
     * Render a component that has no body, such as `<x-icon />`.
     *
     * @param array<string, mixed> $attributes
     * @param string               $slot Content for the default slot.
     */
    public function renderSelfClosing(string $name, array $attributes = [], string $slot = ''): void;

    /**
     * Begin capturing a component's body.
     *
     * @param array<string, mixed> $attributes
     */
    public function start(string $name, array $attributes = []): void;

    /**
     * Finish the innermost component and return its rendered markup.
     */
    public function end(): string;

    /**
     * Begin capturing a named slot.
     */
    public function startNamedSlot(string $name): void;

    /**
     * Finish the innermost named slot and attach it to its component.
     */
    public function endNamedSlot(): void;

    /**
     * Get the values an enclosing component exposed via `@aware`.
     *
     * @param  array<int, string> $keys
     * @return array<string, mixed>
     */
    public function getAwareData(array $keys): array;

    /**
     * Split what a tag was given into declared props and leftover attributes.
     *
     * @param  array<int|string, mixed> $propDefaults
     * @param  array<string, mixed>     $componentData
     * @return array{0: array<string, mixed>, 1: \Nitro\View\Component\ComponentAttributeBag}
     */
    public function resolveComponentProps(array $propDefaults, array $componentData): array;

    /**
     * Replace the data visible to the component currently rendering.
     *
     * @param array<string, mixed> $data
     */
    public function setComponentData(array $data): void;
}
