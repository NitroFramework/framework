<?php

namespace Nitro\View\Concerns;

use InvalidArgumentException;

/**
 * View engine concern: named stacks (`@push` / `@prepend` / `@stack`).
 *
 * Pushes, prepends and the in-progress stack live on the render context, so
 * they reset with every top-level render.
 */
trait ManagesStacks
{
    /**
     * Begin pushing onto a stack, or push the given content outright.
     */
    public function startPush(string $section, string $content = ''): void
    {
        if ($content === '') {
            if (ob_start()) {
                $this->context->pushStack[] = $section;
            }
        } else {
            $this->extendPush($section, $content);
        }
    }

    /**
     * Stop pushing and append what was captured.
     *
     * @return string The stack that was closed.
     * @throws InvalidArgumentException When no push is open.
     */
    public function stopPush(): string
    {
        if (empty($this->context->pushStack)) {
            throw new InvalidArgumentException('Cannot end a push without first starting one.');
        }

        $last = array_pop($this->context->pushStack);
        $this->extendPush($last, ob_get_clean());

        return $last;
    }

    /** Alias of {@see stopPush()}. */
    public function endPush(): void
    {
        $this->stopPush();
    }

    /**
     * Append content to a stack for the current render depth.
     */
    protected function extendPush(string $section, string $content): void
    {
        if (!isset($this->context->pushes[$section])) {
            $this->context->pushes[$section] = [];
        }

        if (!isset($this->context->pushes[$section][$this->context->renderCount])) {
            $this->context->pushes[$section][$this->context->renderCount] = $content;
        } else {
            $this->context->pushes[$section][$this->context->renderCount] .= $content;
        }
    }

    /**
     * Begin prepending to a stack, or prepend the given content outright.
     */
    public function startPrepend(string $section, string $content = ''): void
    {
        if ($content === '') {
            if (ob_start()) {
                $this->context->pushStack[] = $section;
            }
        } else {
            $this->extendPrepend($section, $content);
        }
    }

    /**
     * Stop prepending and prepend what was captured.
     *
     * @return string The stack that was closed.
     * @throws InvalidArgumentException When no prepend is open.
     */
    public function stopPrepend(): string
    {
        if (empty($this->context->pushStack)) {
            throw new InvalidArgumentException('Cannot end a prepend without first starting one.');
        }

        $last = array_pop($this->context->pushStack);
        $this->extendPrepend($last, ob_get_clean());

        return $last;
    }

    /** Alias of {@see stopPrepend()}. */
    public function endPrepend(): void
    {
        $this->stopPrepend();
    }

    /**
     * Prepend content to a stack for the current render depth.
     */
    protected function extendPrepend(string $section, string $content): void
    {
        if (!isset($this->context->prepends[$section])) {
            $this->context->prepends[$section] = [];
        }

        if (!isset($this->context->prepends[$section][$this->context->renderCount])) {
            $this->context->prepends[$section][$this->context->renderCount] = $content;
        } else {
            $this->context->prepends[$section][$this->context->renderCount] = $content . $this->context->prepends[$section][$this->context->renderCount];
        }
    }

    /**
     * Get a stack's contents: prepends first, outermost render depth last.
     */
    public function yieldStack(string $name): string
    {
        if (!isset($this->context->pushes[$name]) && !isset($this->context->prepends[$name])) {
            return '';
        }

        $output = '';

        if (isset($this->context->prepends[$name])) {
            $output .= implode(array_reverse($this->context->prepends[$name]));
        }

        if (isset($this->context->pushes[$name])) {
            $output .= implode($this->context->pushes[$name]);
        }

        return $output;
    }

    /**
     * Determine whether anything has been pushed to a stack.
     */
    public function hasStack(string $name): bool
    {
        return isset($this->context->pushes[$name]) || isset($this->context->prepends[$name]);
    }

    /**
     * Get every stack's pushed content.
     *
     * @return array<string, array<int, string>>
     */
    public function getAllStacks(): array
    {
        return $this->context->pushes;
    }

    /**
     * Discard all stack state and reset the render depth.
     */
    public function flushStacks(): void
    {
        $this->context->pushes = [];
        $this->context->prepends = [];
        $this->context->pushStack = [];
        $this->context->renderCount = 0;
    }
}
