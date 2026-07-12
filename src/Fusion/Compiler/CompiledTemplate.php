<?php

namespace Nitro\Fusion\Compiler;

/**
 * The result of compiling a Fusion component's Blade view to a client render
 * function: the JS render source, the `fusion:` event bindings, and the props
 * bound two-way via `fusion:model`.
 */
final class CompiledTemplate
{
    /**
     * @param string $js     A JS arrow function `(c) => \`...html...\`` — takes the
     *                       component instance and returns HTML for the current state.
     * @param array<int, array{event: string, method: string}> $events fusion:* bindings.
     * @param array<int, string> $models Props bound via fusion:model.
     */
    public function __construct(
        public readonly string $js,
        public readonly array $events,
        public readonly array $models,
    ) {
    }
}
