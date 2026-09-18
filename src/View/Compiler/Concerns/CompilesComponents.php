<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: <x-...> component tags.
 */
trait CompilesComponents
{
    /** Compile the `@component` directive. */
    protected function compileComponent(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php \$this->startComponent({$expression}); ?>";
    }

    /** Compile the `@endcomponent` directive. */
    protected function compileEndcomponent(string $args): string
    {
        return "<?php echo \$this->endComponent(); ?>";
    }

    /** Compile the `@slot` directive. */
    protected function compileSlot(string $args): string
    {
        return "<?php \$this->startNamedSlot{$args}; ?>";
    }

    /** Compile the `@endslot` directive. */
    protected function compileEndslot(string $args): string
    {
        return "<?php \$this->endNamedSlot(); ?>";
    }

    /** Compile the `@props` directive, which declares a component's inputs. */
    protected function compileProps(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php [\$__props, \$attributes] = \$this->resolveComponentProps({$expression}, \$__componentData ?? []); extract(\$__props); ?>";
    }

    /** Compile the `@aware` directive, which inherits values from a parent component. */
    protected function compileAware(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php extract(\$this->getAwareData({$expression})); ?>";
    }
}
