<?php

namespace Nitro\View\Engine;

/**
 * Per-render state for the view engine.
 *
 * Holds ALL transient state of a single top-level render: sections/layout,
 * stacks, fragments, teleports, loops, stream flags, render depth and @once
 * ids. The renderer composes one of these and replaces it per top-level render
 * (ViewRenderer::flushState() = `new RenderContext()`), so render state never
 * accumulates on the long-lived (singleton) renderer — making rendering
 * worker-safe by construction rather than relying on per-field clearing.
 */
final class RenderContext
{
    /** @var array<string, string> Finished sections, keyed by name. */
    public array $sections = [];

    /** @var array<int, string> Stack of in-progress section names. */
    public array $sectionStack = [];

    /** @var string|null Parent view requested via @extends. */
    public ?string $parentView = null;

    /** @var array<string, array<int, string>> Pushed stack content, keyed by [stack][renderDepth]. */
    public array $pushes = [];

    /** @var array<string, array<int, string>> Prepended stack content, keyed by [stack][renderDepth]. */
    public array $prepends = [];

    /** @var array<int, string> Stack names with an in-progress @push/@prepend capture. */
    public array $pushStack = [];

    /** @var array<string, string> Captured @fragment content, keyed by name. */
    public array $fragments = [];

    /** @var array<int, string> Stack of in-progress @fragment names. */
    public array $fragmentStack = [];

    /** @var array<string, string> Captured @teleport content, keyed by target. */
    public array $teleportBuffers = [];

    /** @var string|null Target of the currently active @teleport capture. */
    public ?string $currentTeleport = null;

    /** @var array<int, object> Stack of active @foreach loop frames. */
    public array $loopsStack = [];

    /** Whether a @stream render is currently in progress. */
    public bool $streamingMode = false;

    /** @var string|null Name of the currently active @fill capture (streaming). */
    public ?string $currentFill = null;

    /** Current render depth — 0 at the top-level render; bumped while nesting. */
    public int $renderCount = 0;

    /** @var array<string, true> Ids of @once blocks already rendered this lifecycle. */
    public array $renderedOnce = [];
}

