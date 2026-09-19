<?php

namespace Nitro\Routing;

use Closure;

/**
 * Builds a group's attributes fluently, then hands them to the router.
 *
 * Route::withPrefix('admin')->middleware('auth')->group(fn () => …);
 *
 * Carries nothing of its own: group() passes what it collected to
 * {@see Router::group()}, so nesting, restoration and precedence stay in one
 * place rather than being reimplemented here.
 */
class RouteRegistrar
{
    /** @var array<string, mixed> */
    protected array $attributes = [];

    public function __construct(
        protected Router $router,
    ) {}

    /** @param string|array<int, string> $middleware */
    public function middleware(string|array $middleware): static
    {
        $existing = (array) ($this->attributes['middleware'] ?? []);

        $this->attributes['middleware'] = array_values(array_unique(
            array_merge($existing, (array) $middleware)
        ));

        return $this;
    }

    public function prefix(string $prefix): static
    {
        $this->attributes['prefix'] = $prefix;

        return $this;
    }

    /** The name prefix every route in the group inherits, e.g. 'admin.'. */
    public function name(string $name): static
    {
        $this->attributes['name'] = $name;

        return $this;
    }

    public function domain(string $domain): static
    {
        $this->attributes['domain'] = $domain;

        return $this;
    }

    public function namespace(string $namespace): static
    {
        $this->attributes['namespace'] = $namespace;

        return $this;
    }

    /** Resolve nested models through their parent's relation. */
    public function scopeBindings(bool $scoped = true): static
    {
        $this->attributes['scopeBindings'] = $scoped;

        return $this;
    }

    /** Register the routes with everything collected so far applied. */
    public function group(Closure $callback): Router
    {
        return $this->router->group($this->attributes, $callback);
    }
}
