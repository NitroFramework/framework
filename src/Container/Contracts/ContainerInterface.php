<?php

namespace Nitro\Container\Contracts;

/**
 * Contract for the Nitro service container.
 *
 * Defines the resolution, registration, aliasing, scoping and introspection surface
 * the framework depends on, so consumers bind to this abstraction rather than the
 * concrete {@see \Nitro\Container\Container}.
 */
interface ContainerInterface
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
     */
    public function createOrResolve(string $abstract, array $parameters = []): mixed;

    /**
     * Alias of {@see createOrResolve()} under its conventional name. Identical
     * behaviour; both are supported.
     */
    public function make(string $abstract, array $parameters = []): mixed;

    /**
     * STRICT registry lookup: resolve a registered binding, throwing
     * NotFoundException when there is none.
     *
     * The difference from createOrResolve() is what happens for an unregistered
     * name — get() fails, createOrResolve() auto-wires. Reach for get() only
     * where an unknown name is a bug worth hearing about (a service name read
     * from config, for instance).
     */
    public function get(string $name): mixed;

    /** Check if a service is registered */
    public function has(string $name): bool;

    /** Get a service or return default if not found */
    public function getOrDefault(string $name, $default = null): mixed;

    /** Invoke a callable with auto-wired dependencies */
    public function call(callable $callable, array $parameters = []): mixed;

    /** Register the route-model-binding parameter resolver. */
    public function bindParametersUsing(\Closure $resolver): void;

    // ============================================
    // REGISTRATION
    // ============================================

    /** Bind a service or value */
    public function bind(string $name, $value, bool $singleton = true): void;

    /** Register a singleton service */
    public function singleton(string $name, $value = null): void;

    /** Register a request-scoped service (flushed by forgetScopedInstances). */
    public function scoped(string $name, $value = null): void;

    /** Register an already-created instance */
    public function instance(string $name, mixed $instance): void;

    /** Register a factory (non-singleton) */
    public function factory(string $name, callable $factory): void;

    /** Register an alias that resolves to an existing binding */
    public function alias(string $alias, string $abstract): void;

    // ============================================
    // CONTEXTUAL & TAGGING
    // ============================================

    /** Register a contextual binding (by param name or type name) */
    public function contextual(string $needer, string $needed, string|callable $concrete): void;

    /** Tag abstracts under a group name */
    public function tag(string $tag, string ...$abstracts): void;

    /** Resolve all tagged services */
    public function tagged(string $tag): array;

    // ============================================
    // LIFECYCLE
    // ============================================

    /** Remove a service from the registry */
    public function forget(string $name): void;

    /** Forget resolved instances without unregistering (worker mode) */
    public function forgetScoped(array $names): void;

    /** Flush every scoped() binding's resolved instance (worker mode). */
    public function forgetScopedInstances(): void;

    // ============================================
    // INTROSPECTION
    // ============================================

    /** Get all registered service names */
    public function getServiceNames(): array;

    /** Get all currently resolved instances */
    public function getResolvedInstances(): array;

    /** Return structured reflection data for a class (FIX #8: returns array, not void) */
    public function debugReflection(string $abstract): array;
}