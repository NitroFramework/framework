<?php

namespace Nitro\Container\Contracts;

use Closure;
use Illuminate\Contracts\Container\Container as IlluminateContainerContract;

/**
 * Contract for the Nitro service container.
 *
 * Extends the Illuminate container contract — which is where bind(),
 * singleton(), scoped(), instance(), make(), call(), when(), tag() and the
 * resolution callbacks are declared — and adds the framework's own surface on
 * top, so consumers bind to this abstraction rather than the concrete
 * {@see \Nitro\Container\Container}.
 */
interface ContainerInterface extends IlluminateContainerContract
{
    // ============================================
    // CORE RESOLUTION
    // ============================================

    /**
     * Resolve the registered binding for $abstract, or CREATE it by auto-wiring
     * its constructor when nothing is bound. The framework's standard way to ask
     * the container for an instance.
     *
     * Respects $parameters as constructor overrides.
     *
     * @param  string|callable $abstract
     * @param  array           $parameters
     * @param  bool            $raiseEvents
     * @return mixed
     */
    public function resolve($abstract, $parameters = [], $raiseEvents = true);

    /** Check if a service is registered. */
    public function has(string $name): bool;

    /** Get a service or return default if not found. */
    public function getOrDefault(string $name, $default = null): mixed;


    // ============================================
    // REGISTRATION
    // ============================================

    /** Register a contextual binding (by param name or type name). */
    public function contextual(string $needer, string $needed, string|callable $concrete): void;

    /** Whether anything has been bound contextually for $consumer. */
    public function hasContextualBindings(string $consumer): bool;

    // ============================================
    // LIFECYCLE
    // ============================================

    /** Remove a service from the registry. */
    public function forget(string $name): void;

    /**
     * Forget resolved instances without unregistering (worker mode).
     *
     * @param array<int, string> $names
     */
    public function forgetScoped(array $names): void;

    /** Flush every scoped() binding's resolved instance (worker mode). */
    public function forgetScopedInstances();

    /**
     * Be told about every object the container hands out, as ($name, $object).
     *
     * Passing null stops it. The one hook a watcher cannot build from the
     * resolution callbacks, which never fire for instance().
     */
    public function observeResolutions(?Closure $observer): void;

    // ============================================
    // INTROSPECTION
    // ============================================

    /**
     * Get all registered service names.
     *
     * @return array<int, string>
     */
    public function getServiceNames(): array;

    /**
     * Get all currently resolved instances.
     *
     * @return array<string, mixed>
     */
    public function getResolvedInstances(): array;

    /**
     * Every registered binding, as name => the value it was bound to.
     *
     * @return array<string, mixed>
     */
    public function registeredBindings(): array;

    /**
     * Alias name => the abstract it resolves to.
     *
     * @return array<string, string>
     */
    public function registeredAliases(): array;

    /**
     * Structured reflection data for a class.
     *
     * @return array<string, mixed>
     */
    public function debugReflection(string $abstract): array;
}
