<?php

use Nitro\Container\Container;

if (!function_exists('app')) {
    /**
     * The service container, or a resolved service from it.
     *
     *   app()                 // the container itself
     *   app(UserRepo::class)  // resolve a binding, or auto-wire it
     *   app('paths')          // resolve by alias
     *
     * `app()` is the container (as in Laravel) — the canonical way to reach it
     * from OUTSIDE a class that has one. Inside a class that holds a container,
     * use it: `$this->container->resolve(...)`. That split is the rule
     * the framework's own code follows — a global lookup where there is nothing
     * to inject (helpers, compiled Blade output, static entry points), the
     * injected container everywhere else.
     *
     * Resolution goes through resolve(), so an unbound-but-constructible
     * class is auto-wired rather than throwing.
     *
     * The Application is a distinct object; ask for it by name when you need it:
     * app(\Nitro\Foundation\Application::class).
     *
     * @param string|null $abstract Service to resolve, or null for the container.
     * @return mixed
     */
    function app(?string $abstract = null)
    {
        $container = Container::getInstance();

        if ($abstract === null) {
            return $container;
        }

        return $container->resolve($abstract);
    }
}
