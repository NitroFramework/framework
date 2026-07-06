<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: the @stream streaming directive.
 */
trait CompilesStream
{
    protected function compileStream(): string
    {
        // The marker comment lets isStreamView() detect stream templates
        // by reading the compiled file without executing it
        return '<?php /* @nitro-stream */ $this->startStream(); ?>';
    }

    protected function compileEndstream(): string
    {
        return '<?php $this->endStream(); ?>';
    }

    protected function compileHole(string $expression): string
    {
        $name = trim($expression, "()'\" ");
        return '<?php $this->renderHole(\'' . addslashes($name) . '\'); ?>';
    }

    protected function compileFill(string $expression): string
    {
        $name = trim($expression, "()'\" ");
        return '<?php $this->startFill(\'' . addslashes($name) . '\'); ?>';
    }

    protected function compileEndfill(): string
    {
        return '<?php $this->endFill(); ?>';
    }
}
