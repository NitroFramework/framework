<?php

namespace Nitro\View\Engine\Concerns;

use InvalidArgumentException;

/**
 * View engine concern: @fragment capture and rendering.
 */
trait ManagesFragments
{
    /**
     * Fragment state (captured fragments + in-progress stack) lives on
     * {@see \Nitro\View\Engine\RenderContext} via $this->context.
     */

    /**
     * Start capturing a fragment (@fragment).
     */
    public function startFragment(string $fragment): void
    {
        if (ob_start()) {
            $this->context->fragmentStack[] = $fragment;
        }
    }

    /**
     * 
     * Stop capturing the fragment (@endfragment).
     */
    public function stopFragment(): string
    {
        if (empty($this->context->fragmentStack)) {
            throw new InvalidArgumentException('Cannot end a fragment without first starting one.');
        }

        $last = array_pop($this->context->fragmentStack);

        $this->context->fragments[$last] = ob_get_clean();

        return $this->context->fragments[$last];
    }

    /**
     * Get a captured fragment by name.
     */
    public function getFragment(string $name, ?string $default = null): ?string
    {
        return $this->context->fragments[$name] ?? $default;
    }

    /**
     * Get all captured fragments.
     */
    public function getFragments(): array
    {
        return $this->context->fragments;
    }

    /**
     * Reset all fragment state.
     */
    public function flushFragments(): void
    {
        $this->context->fragments = [];
        $this->context->fragmentStack = [];
    }
}
