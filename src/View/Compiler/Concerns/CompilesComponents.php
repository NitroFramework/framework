<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: <x-...> component tags.
 */
trait CompilesComponents
{
    protected function compileComponent(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php \$this->startComponent({$expression}); ?>";
    }

    protected function compileEndcomponent(string $args): string
    {
        return "<?php echo \$this->endComponent(); ?>";
    }

    protected function compileSlot(string $args): string
    {
        return "<?php \$this->startNamedSlot{$args}; ?>";
    }

    protected function compileEndslot(string $args): string
    {
        return "<?php \$this->endNamedSlot(); ?>";
    }

    protected function compileProps(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php [\$__props, \$attributes] = \$this->resolveComponentProps({$expression}, \$__componentData ?? []); extract(\$__props); ?>";
    }

    protected function compileAware(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php extract(\$this->getAwareData({$expression})); ?>";
    }
}
