<?php

use Nitro\Container\Container;

if (!function_exists('app')) {
    /**
     * Get the Application instance or resolve a service from container
     * 
     * @param string|null $abstract Service to resolve
     * @return mixed
     */
    function app(?string $abstract = null)
    {
        $container = Container::getInstance();

        if ($abstract === null) {
            return $container->get('app');
        }

        return $container->make($abstract); // use make() not get()
    }
}
