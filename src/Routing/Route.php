<?php

namespace Nitro\Routing;

/**
 * Matched-route value object.
 *
 * An immutable data container describing a route after it has been matched:
 * its type, handler, bound URL parameters, view data, middleware and name.
 * Produced by the {@see Router} and consumed by the {@see RouteDispatcher}.
 */
class Route
{
    protected string $type;
    protected mixed $handler;
    protected array $parameters;
    protected array $data;
    protected array $middleware;
    protected ?string $name;
    protected ?string $component;
    protected ?string $action;

    /**
     * Custom route keys from "{post:slug}", by parameter name.
     *
     * @var array<string, string>
     */
    protected array $bindingFields = [];

    /** Whether a nested model resolves through its parent's relation. */
    protected bool $scoped = false;

    /** Whether a soft-deleted model still binds. */
    protected bool $withTrashed = false;

    /** Called instead of a 404 when a bound model is not found. */
    protected mixed $missing = null;

    /**
     * Parameter names in the order the path declares them.
     *
     * @var array<int, string>
     */
    protected array $parameterOrder = [];

    /**
     * Route types
     */
    const TYPE_CONTROLLER = 'controller';
    const TYPE_CLOSURE = 'closure';
    const TYPE_CALLABLE = 'callable';
    const TYPE_VIEW = 'view';

    /**
     * Constructor
     */
    public function __construct(
        string $type,
        mixed $handler,
        array $parameters = [],
        array $data = [],
        array $middleware = [],
        ?string $name = null,
        ?string $component = null,
        ?string $action = null
    ) {
        $this->type = $type;
        $this->handler = $handler;
        $this->parameters = $parameters;
        $this->data = $data;
        $this->middleware = $middleware;
        $this->name = $name;
        $this->component = $component;
        $this->action = $action;
    }

    /**
     * Get route type
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get route handler
     */
    public function getHandler(): mixed
    {
        return $this->handler;
    }

    /**
     * Get route parameters (from URL)
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Get specific parameter
     */
    public function getParameter(string $key, $default = null)
    {
        return $this->parameters[$key] ?? $default;
    }

    /**
     * Get additional data (for view routes)
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Get route middleware
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * A free-form label a feature layer may attach to a route, or null.
     *
     * The core does not interpret it; it exists so a layer registering routes
     * through a Router macro can carry its own metadata on the matched route
     * without the core knowing what that layer is.
     */
    public function getComponent(): ?string
    {
        return $this->component;
    }

    /** A second free-form label, alongside {@see getComponent()}. */
    public function getAction(): ?string
    {
        return $this->action;
    }

    /**
     * Get route name
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Check if route has middleware
     */
    public function hasMiddleware(): bool
    {
        return !empty($this->middleware);
    }

    /**
     * Check if route has specific middleware
     */
    public function hasMiddlewareNamed(string $name): bool
    {
        return in_array($name, $this->middleware);
    }

    /**
     * Check if route is named
     */
    public function isNamed(): bool
    {
        return $this->name !== null;
    }

    /**
     * Check if route is controller type
     */
    public function isController(): bool
    {
        return $this->type === self::TYPE_CONTROLLER;
    }

    /**
     * Check if route is closure type
     */
    public function isClosure(): bool
    {
        return $this->type === self::TYPE_CLOSURE;
    }

    /**
     * Check if route is callable type
     */
    public function isCallable(): bool
    {
        return $this->type === self::TYPE_CALLABLE;
    }

    /**
     * Check if route is view type
     */
    public function isView(): bool
    {
        return $this->type === self::TYPE_VIEW;
    }

    /**
     * Create controller route match
     */
    public static function controller(
        string $controller,
        string $method,
        array $parameters = [],
        array $middleware = [],
        ?string $name = null
    ): self {
        return new self(
            self::TYPE_CONTROLLER,
            [$controller, $method],
            $parameters,
            [],
            $middleware,
            $name
        );
    }

    /**
     * Create closure route match
     */
    public static function closure(
        \Closure $closure,
        array $parameters = [],
        array $middleware = [],
        ?string $name = null
    ): self {
        return new self(
            self::TYPE_CLOSURE,
            $closure,
            $parameters,
            [],
            $middleware,
            $name
        );
    }

    /**
     * Create callable route match
     */
    public static function callable(
        callable $callable,
        array $parameters = [],
        array $middleware = [],
        ?string $name = null
    ): self {
        return new self(
            self::TYPE_CALLABLE,
            $callable,
            $parameters,
            [],
            $middleware,
            $name
        );
    }

    /**
     * Create view route match
     */
    public static function view(
        string $viewName,
        array $data = [],
        array $parameters = [],
        array $middleware = [],
        ?string $name = null
    ): self {
        return new self(
            self::TYPE_VIEW,
            $viewName,
            $parameters,
            $data,
            $middleware,
            $name
        );
    }

    /**
     * Create a route of a kind a feature layer contributed.
     *
     * The four constructors above cover what the router itself understands;
     * this covers everything a {@see \Nitro\Routing\Contracts\RouteType} adds.
     * The handler is whatever that type asked to store, and is kept as-is —
     * so it should be a name or an id rather than a closure, or the route
     * cannot be cached.
     */
    public static function ofType(
        string $type,
        mixed $handler,
        array $parameters = [],
        array $middleware = [],
        ?string $name = null
    ): self {
        return new self(
            $type,
            $handler,
            $parameters,
            [],
            $middleware,
            $name
        );
    }

    /**
     * Get controller class (for controller routes)
     */
    public function getControllerClass(): ?string
    {
        if ($this->isController() && is_array($this->handler)) {
            return $this->handler[0] ?? null;
        }
        return null;
    }

    /**
     * Get controller method (for controller routes)
     */
    public function getControllerMethod(): ?string
    {
        if ($this->isController() && is_array($this->handler)) {
            return $this->handler[1] ?? null;
        }
        return null;
    }

    /**
     * Get view name (for view routes)
     */
    public function getViewName(): ?string
    {
        if ($this->isView() && is_string($this->handler)) {
            return $this->handler;
        }
        return null;
    }

    /**
     * Convert to array for debugging/serialization
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'handler' => $this->handler,
            'parameters' => $this->parameters,
            'data' => $this->data,
            'middleware' => $this->middleware,
            'name' => $this->name,
        ];
    }

    /**
     * The route's bound parameters.
     *
     * Matching supplies both the placeholder names and their numeric positions,
     * so positional binding still works for handlers whose argument names do
     * not match the URL. This returns the named pairs only.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return array_filter(
            $this->parameters,
            static fn ($key) => is_string($key),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * One bound parameter by name.
     */
    public function parameter(string $name, mixed $default = null): mixed
    {
        return $this->parameters[$name] ?? $default;
    }

    /**
     * Whether a parameter of this name was bound and is not null.
     */
    public function hasParameter(string $name): bool
    {
        return isset($this->parameters[$name]);
    }

    /**
     * Whether the route binds any parameters at all.
     */
    public function hasParameters(): bool
    {
        return $this->parameters() !== [];
    }

    /**
     * The names of the route's bound parameters.
     *
     * @return array<int, string>
     */
    public function parameterNames(): array
    {
        return array_keys($this->parameters());
    }

    /**
     * Drop a bound parameter.
     */
    public function forgetParameter(string $name): static
    {
        unset($this->parameters[$name]);

        return $this;
    }

    /**
     * Bind a parameter, overwriting any existing value.
     */
    public function setParameter(string $name, mixed $value): static
    {
        $this->parameters[$name] = $value;

        return $this;
    }

    /**
     * Record the custom route keys the path declared, by parameter name.
     *
     * @param array<string, string> $fields
     */
    public function setBindingFields(array $fields): static
    {
        $this->bindingFields = $fields;

        return $this;
    }

    /**
     * The column a parameter binds by, as declared by "{post:slug}".
     *
     * Null means the parameter named no column, and a model should be looked
     * up by its own route key.
     */
    public function getBindingField(string $parameter): ?string
    {
        return $this->bindingFields[$parameter] ?? null;
    }

    /** @return array<string, string> */
    public function getBindingFields(): array
    {
        return $this->bindingFields;
    }

    /**
     * Record the binding behaviour the route was registered with.
     *
     * @param array<int, string> $order Parameter names as the path declares them.
     */
    public function setBindingBehaviour(bool $scoped, bool $withTrashed, mixed $missing, array $order = []): static
    {
        $this->scoped = $scoped;
        $this->withTrashed = $withTrashed;
        $this->missing = $missing;
        $this->parameterOrder = $order;

        return $this;
    }

    /** Whether a nested model resolves through its parent's relation. */
    public function isScoped(): bool
    {
        return $this->scoped;
    }

    /** Whether a soft-deleted model still binds. */
    public function includesTrashed(): bool
    {
        return $this->withTrashed;
    }

    /** What to call instead of 404ing when a bound model is not found. */
    public function missingHandler(): ?callable
    {
        return is_callable($this->missing) ? $this->missing : null;
    }

    /**
     * The parameter a scoped child resolves through — the one declared before
     * it — or null for the first parameter, which has no parent.
     */
    public function parentParameter(string $name): ?string
    {
        $index = array_search($name, $this->parameterOrder, true);

        return $index === false || $index === 0 ? null : $this->parameterOrder[$index - 1];
    }

    /**
     * The route's name, or null when it was never named.
     */
    public function name(): ?string
    {
        return $this->name;
    }

    /**
     * Whether the route's name matches any of the given patterns, where `*`
     * stands for any run of characters.
     */
    public function named(string ...$patterns): bool
    {
        if ($this->name === null) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if ($pattern === $this->name) {
                return true;
            }

            if (! str_contains($pattern, '*')) {
                continue;
            }

            $regex = str_replace('\*', '.*', preg_quote($pattern, '#'));

            if (preg_match('#^' . $regex . '\z#u', $this->name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Controller@method" for a controller route, or null for any other type.
     */
    public function getActionName(): ?string
    {
        if (! $this->isController()) {
            return null;
        }

        return $this->getControllerClass() . '@' . $this->getControllerMethod();
    }

    /**
     * The middleware names the route declared.
     *
     * @return array<int, string>
     */
    public function middleware(): array
    {
        return $this->middleware;
    }
}
