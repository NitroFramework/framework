<?php

use Nitro\Inertia\Response;
use Nitro\Inertia\ResponseFactory;

if (!function_exists('inertia')) {
    /**
     * Render an Inertia page, or get the factory.
     *
     * Called with a component name it builds the page; called with nothing it
     * hands back the factory, which is what makes `inertia()->share(...)` and
     * `inertia()->location(...)` read the way they do.
     *
     * @param  array<array-key, mixed> $props
     * @return ($component is null ? ResponseFactory : Response)
     */
    function inertia(?string $component = null, array $props = []): ResponseFactory|Response
    {
        $factory = app('inertia');

        return $component === null ? $factory : $factory->render($component, $props);
    }
}

if (!function_exists('inertia_location')) {
    /**
     * Send the client to a URL outside the application.
     *
     * Needed because a plain redirect cannot leave an Inertia app: the client
     * follows it with fetch and gets a document it cannot mount.
     */
    function inertia_location(string $url): Nitro\Http\Response
    {
        return app('inertia')->location($url);
    }
}
