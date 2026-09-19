<?php

namespace Nitro\Routing;

use Nitro\Routing\Contracts\RouteType;

/**
 * The route kinds contributed by feature layers.
 *
 * The router and the dispatcher both consult this when they meet a route that
 * is not one of their own four, which is what keeps them from naming a layer.
 * Empty in a bare application, and both lookups cost nothing when it is.
 */
class RouteTypes
{
    /** @var array<string, RouteType> Keyed by {@see RouteType::name()}. */
    protected array $types = [];

    /**
     * Register a type, replacing any earlier one of the same name so an
     * application can override a layer's.
     */
    public function add(RouteType $type): static
    {
        $this->types[$type->name()] = $type;

        return $this;
    }

    /**
     * The type registered under a name, or null when nothing claims it.
     */
    public function get(string $name): ?RouteType
    {
        return $this->types[$name] ?? null;
    }

    /**
     * Offer a handler to each registered type until one claims it.
     *
     * @return array{type: string, handler: mixed}|null Route data in the shape
     *         the router stores, or null when no type wants it.
     */
    public function parse(mixed $handler): ?array
    {
        foreach ($this->types as $name => $type) {
            $parsed = $type->parse($handler);

            if ($parsed !== null) {
                return ['type' => $name, 'handler' => $parsed];
            }
        }

        return null;
    }

    /**
     * @return array<string, RouteType>
     */
    public function all(): array
    {
        return $this->types;
    }
}
