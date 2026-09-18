<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: the @stream streaming directive.
 */
trait CompilesStream
{
    /**
     * Compile the `@stream` directive.
     *
     * The marker comment lets a stream template be recognised by reading the
     * compiled file, without executing it.
     */
    protected function compileStream(): string
    {
        return '<?php /* @nitro-stream */ $this->startStream(); ?>';
    }

    /** Compile the `@endstream` directive. */
    protected function compileEndstream(): string
    {
        return '<?php $this->endStream(); ?>';
    }

    /** Compile the `@hole` directive. */
    protected function compileHole(string $expression): string
    {
        $name = trim($expression, "()'\" ");
        return '<?php $this->renderHole(\'' . addslashes($name) . '\'); ?>';
    }

    /** Compile the `@fill` directive. */
    protected function compileFill(string $expression): string
    {
        $name = trim($expression, "()'\" ");
        return '<?php $this->startFill(\'' . addslashes($name) . '\'); ?>';
    }

    /** Compile the `@endfill` directive. */
    protected function compileEndfill(): string
    {
        return '<?php $this->endFill(); ?>';
    }
}
