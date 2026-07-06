<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: @fragment blocks.
 */
trait CompilesFragments
{
    protected function compileFragment(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php \$this->startFragment({$expression}); ?>";
    }

    protected function compileEndfragment(string $args): string
    {
        return "<?php echo \$this->stopFragment(); ?>";
    }

    protected function compileTeleport(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php \$this->startTeleport({$expression}); ?>";
    }

    protected function compileEndteleport(string $args): string
    {
        return "<?php \$this->endTeleport(); ?>";
    }

    protected function compileTeleportTarget(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php echo \$this->yieldTeleport({$expression}); ?>";
    }
}
