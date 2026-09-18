<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: @fragment blocks.
 */
trait CompilesFragments
{
    /** Compile the `@fragment` directive. */
    protected function compileFragment(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php \$this->startFragment({$expression}); ?>";
    }

    /** Compile the `@endfragment` directive. */
    protected function compileEndfragment(string $args): string
    {
        return "<?php echo \$this->stopFragment(); ?>";
    }

    /** Compile the `@teleport` directive. */
    protected function compileTeleport(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php \$this->startTeleport({$expression}); ?>";
    }

    /** Compile the `@endteleport` directive. */
    protected function compileEndteleport(string $args): string
    {
        return "<?php \$this->endTeleport(); ?>";
    }

    /** Compile the `@teleportTarget` directive. */
    protected function compileTeleportTarget(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php echo \$this->yieldTeleport({$expression}); ?>";
    }
}
