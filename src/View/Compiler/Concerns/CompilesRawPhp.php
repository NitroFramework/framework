<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: @php raw PHP blocks.
 */
trait CompilesRawPhp
{
    /** Compile the `@php` directive. */
    protected function compilePhp(string $args): string
    {
        if (trim($args) === '') {
            return "<?php ";
        }

        $expression = trim($this->stripParentheses($args));

        if ($expression === '') {
            return "<?php ";
        }

        return "<?php {$expression}; ?>";
    }

    /** Compile the `@endphp` directive. */
    protected function compileEndphp(string $args): string
    {
        return " ?>";
    }
}
