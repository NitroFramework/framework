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

    /** Get a registered service by name */
    public function get(string $name): mixed;

    /** Check if a service is registered */
    public function has(string $name): bool;

    /** Get a service or return default if not found */
    public function getOrDefault(string $name, $default = null): mixed;

    /** Make a class instance, auto-wiring dependencies. Respects $parameters. */
    public function make(string $abstract, array $parameters = []): mixed;

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
    // PROFILING (dev-time, opt-in)
    // ============================================

    /** Attach a profiler to record container activity, or null to disable it. */
    public function setProfiler(?ProfilerInterface $profiler): void;

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