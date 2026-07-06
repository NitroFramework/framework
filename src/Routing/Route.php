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
     * Route types
     */
    const TYPE_CONTROLLER = 'controller';
    const TYPE_CLOSURE = 'closure';
    const TYPE_CALLABLE = 'callable';
    const TYPE_VIEW = 'view';
    // add for htmx components
    const TYPE_HTMX_COMPONENT = 'htmx_component';

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
     * Get component name (for htmx component routes)
     */
    public function getComponent(): ?string
    {
        return $this->component;
    }

    /**
     * Get action name (for htmx component routes)
     */
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
}
