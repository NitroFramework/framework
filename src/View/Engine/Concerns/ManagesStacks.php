<?php

namespace Nitro\View\Engine\Concerns;

use InvalidArgumentException;

/**
 * View engine concern: named stacks (@push/@stack).
 */
trait ManagesStacks
{
    /**
     * Stack state (pushes, prepends, in-progress stack) lives on
     * {@see \Nitro\View\Engine\RenderContext} via $this->context, so it resets
     * per top-level render. (renderCount remains on the renderer.)
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

    public function stopPush(): string
    {
        if (empty($this->context->pushStack)) {
            throw new InvalidArgumentException('Cannot end a push without first starting one.');
        }

        $last = array_pop($this->context->pushStack);
        $this->extendPush($last, ob_get_clean());

        return $last;
    }

    public function endPush(): void
    {
        $this->stopPush();
    }

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

    public function stopPrepend(): string
    {
        if (empty($this->context->pushStack)) {
            throw new InvalidArgumentException('Cannot end a prepend without first starting one.');
        }

        $last = array_pop($this->context->pushStack);
        $this->extendPrepend($last, ob_get_clean());

        return $last;
    }

    public function endPrepend(): void
    {
        $this->stopPrepend();
    }

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

    public function hasStack(string $name): bool
    {
        return isset($this->context->pushes[$name]) || isset($this->context->prepends[$name]);
    }

    public function getAllStacks(): array
    {
        return $this->context->pushes;
    }

    public function flushStacks(): void
    {
        $this->context->pushes = [];
        $this->context->prepends = [];
        $this->context->pushStack = [];
        $this->context->renderCount = 0;
    }
}
