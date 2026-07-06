<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: assorted directives not covered by the other concerns.
 */
trait CompilesMiscellaneous
{
    protected function compileOnce(string $args): string
    {
        $id = !empty($args)
            ? $this->stripParentheses($args)
            : "'" . bin2hex(random_bytes(16)) . "'";

        return "<?php if(!\$this->hasRenderedOnce({$id})): \$this->markRenderedOnce({$id}); ?>";
    }

    protected function compileEndonce(string $args): string
    {
        return "<?php endif; ?>";
    }

    protected function compileError(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php if(isset(\$errors[{$expression}]) && !empty(\$errors[{$expression}])): ?>";
    }

    protected function compileEnderror(string $args): string
    {
        return "<?php endif; ?>";
    }

    protected function compileElapsed_time(string $args): string
    {
        return "<?php echo \\Nitro\\PerformanceBar\\PerformanceMetrics::elapsedTime(); ?>";
    }

    protected function compileMemory_usage(string $args): string
    {
        return "<?php echo \\Nitro\\PerformanceBar\\PerformanceMetrics::memoryUsage(); ?>";
    }
}
