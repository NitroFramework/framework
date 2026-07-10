<?php

use Nitro\Container\Container;

if (!function_exists('app')) {
    /**
     * The service container, or a resolved service from it.
     *
     *   app()                 // the container itself
     *   app(UserRepo::class)  // resolve a binding (make)
     *   app('paths')          // resolve by alias
     *
     * `app()` is the container (as in Laravel) — the one canonical way to reach it
     * from outside a class that has it injected. The Application is a distinct
     * object; ask for it by name when you need it: app(\Nitro\Foundation\Application::class).
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

        return $container->make($abstract); // use make() not get()
    }
}
