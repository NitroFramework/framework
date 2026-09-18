<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: @inject service injection.
 */
trait CompilesInjections
{
    /**
     * Compile the `@inject` directive, which resolves a service into the view.
     *
     * Both the variable name and the class identifier are interpolated into the
     * compiled PHP, so both are validated strictly.
     *
     * @throws InvalidArgumentException When either name is not an identifier.
     */
    protected function compileInject(string $args): string
    {
        $args  = trim($args, '()');
        $parts = array_map('trim', explode(',', $args, 2));
        $var   = trim($parts[0] ?? '', "'\"");
        $class = trim($parts[1] ?? '', "'\"");
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $var)) {
            throw new \InvalidArgumentException("Invalid @inject variable name: {$var}");
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $class)) {
            throw new \InvalidArgumentException("Invalid @inject class name: {$class}");
        }

        return "<?php \${$var} = app('{$class}'); ?>";
    }
}
