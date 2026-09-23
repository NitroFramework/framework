<?php

namespace Nitro\Routing;

/**
 * A resource whose actions are still being decided.
 *
 * Route::resource('books', BookController::class)->only(['index', 'show'])
 * cannot register as it is written: by the time only() is reached the seven
 * routes would already exist, and unregistering four of them is not something
 * a router should have to do.
 *
 * So resource() hands back one of these instead and nothing is registered
 * until the statement ends — at which point PHP destroys the object and
 * {@see __destruct()} registers whatever the chain settled on. A chain that
 * says nothing registers all seven, which is what a bare resource() means.
 */
class PendingResourceRegistration
{
    private bool $registered = false;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly Router $router,
        private readonly string $name,
        private readonly string $controller,
        private array $options = [],
    ) {
    }

    /**
     * Register only these actions.
     *
     * @param array<int, string>|string $actions
     */
    public function only(array|string $actions): static
    {
        $this->options['only'] = (array) $actions;

        return $this;
    }

    /**
     * Register every action but these.
     *
     * @param array<int, string>|string $actions
     */
    public function except(array|string $actions): static
    {
        $this->options['except'] = (array) $actions;

        return $this;
    }

    /**
     * Name the route parameter, in place of the singular of the resource.
     *
     * Route::resource('users', …)->parameter('admin') routes /users/{admin}.
     */
    public function parameter(string $parameter): static
    {
        $this->options['parameter'] = $parameter;

        return $this;
    }

    /**
     * Give every route in the resource a middleware.
     *
     * @param array<int, string>|string $middleware
     */
    public function middleware(array|string $middleware): static
    {
        $this->options['middleware'] = (array) $middleware;

        return $this;
    }

    /**
     * Prefix the names the resource registers under.
     *
     * Route::resource('photos', …)->names('gallery') names them gallery.index
     * and so on, rather than photos.index.
     */
    public function names(string $names): static
    {
        $this->options['names'] = $names;

        return $this;
    }

    /** Register now rather than at the end of the statement. */
    public function register(): Router
    {
        if ($this->registered) {
            return $this->router;
        }

        $this->registered = true;

        return $this->router->registerResource($this->name, $this->controller, $this->options);
    }

    public function __destruct()
    {
        $this->register();
    }
}
