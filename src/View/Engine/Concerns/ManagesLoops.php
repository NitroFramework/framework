<?php

namespace Nitro\View\Engine\Concerns;

/**
 * View engine concern: the $loop variable and loop stack.
 */
trait ManagesLoops
{
    /**
     * Stack of in-progress loops. Each entry is a stdClass with mutable
     * public fields so `$loop->iteration++` style updates don't allocate
     * a new array per iteration. The compiled foreach assigns the top
     * frame to `$loop` once per iteration; subsequent reads of
     * `$loop->index` / `$loop->first` / etc. are direct property reads.
     *
     * Loop state lives on {@see \Nitro\View\Engine\RenderContext} via
     * $this->context->loopsStack so it resets per top-level render.
     *
     * @var array<int, \stdClass>
     */

    /**
     * Push a new loop frame. We allocate ONE stdClass per loop nesting
     * (not per iteration). All subsequent updates mutate this object in
     * place — the foreach pays a single object allocation regardless of
     * how many rows it walks.
     */
    public function addLoop(mixed $data): void
    {
        $length = is_countable($data) ? count($data) : null;
        $parent = end($this->context->loopsStack) ?: null;

        $frame = new \stdClass();
        $frame->iteration = 0;
        $frame->index     = 0;
        $frame->remaining = $length;
        $frame->count     = $length;
        $frame->first     = true;
        $frame->last      = $length === 1;
        $frame->odd       = false;
        $frame->even      = true;
        $frame->depth     = count($this->context->loopsStack) + 1;
        $frame->parent    = $parent; // already a stdClass when present

        $this->context->loopsStack[] = $frame;
    }

    /**
     * Bump the top frame's counters in place. The previous version called
     * `array_merge($loop, [...])` per iteration which copied the entire
     * loop struct each time; for a 1000-row table that's 1000 throwaway
     * arrays. Direct property writes are O(1) with no allocation.
     */
    public function incrementLoopIndices(): void
    {
        $top = $this->context->loopsStack[count($this->context->loopsStack) - 1] ?? null;
        if ($top === null) {
            return;
        }

        $top->iteration++;
        $top->index = $top->iteration - 1;
        $top->first = $top->iteration === 1;
        $top->odd   = !$top->odd;
        $top->even  = !$top->even;

        if ($top->count !== null) {
            $top->remaining = $top->count - $top->iteration;
            $top->last      = $top->iteration === $top->count;
        }
    }

    /** Pop a loop from the stack. */
    public function popLoop(): void
    {
        array_pop($this->context->loopsStack);
    }

    /**
     * Return the top loop frame for the compiled `$loop = $this->getLastLoop()`
     * assignment. The frame is already a stdClass, so no per-iteration
     * (object) cast happens here.
     */
    public function getLastLoop(): ?\stdClass
    {
        $count = count($this->context->loopsStack);
        return $count === 0 ? null : $this->context->loopsStack[$count - 1];
    }

    public function getLoopStack(): array
    {
        return $this->context->loopsStack;
    }
}
