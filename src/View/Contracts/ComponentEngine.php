<?php

namespace Nitro\View\Contracts;

/**
 * Renders Blade components and manages component/slot stacks.
 */
interface ComponentEngine
{
    public function renderSelfClosing(string $name, array $attributes = [], string $slot = ''): void;
    public function start(string $name, array $attributes = []): void;
    public function end(): string;
    public function startNamedSlot(string $name): void;
    public function endNamedSlot(): void;
    public function getAwareData(array $keys): array;
    public function resolveComponentProps(array $propDefaults, array $componentData): array;
    public function setComponentData(array $data): void;
}