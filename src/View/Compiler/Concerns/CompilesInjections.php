<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: @inject service injection.
 */
trait CompilesInjections
{
    protected function compileInject(string $args): string
    {
        $args  = trim($args, '()');
        $parts = array_map('trim', explode(',', $args, 2));
        $var   = trim($parts[0] ?? '', "'\"");
        $class = trim($parts[1] ?? '', "'\"");

        // Both the variable name and class identifier are interpolated into
        // compiled PHP. Validate strictly to prevent compile-time injection.
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $var)) {
            throw new \InvalidArgumentException("Invalid @inject variable name: {$var}");
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $class)) {
            throw new \InvalidArgumentException("Invalid @inject class name: {$class}");
        }

        return "<?php \${$var} = \\Nitro\\Container\\Container::getInstance()->make('{$class}'); ?>";
    }
}
