<?php

namespace Nitro\Container\Concerns;

use Closure;
use Nitro\Container\ContextualBindingBuilder;

/**
 * Binding introspection, conditional registration, contextual binding and
 * instance lifecycle for the container.
 *
 * Nothing here runs during resolution; these are registration-time and
 * inspection helpers, so they add no cost to the hot path.
 */
trait ManagesBindings
{
    /** @var array<string, Closure> */
    private array $methodBindings = [];

    /** Whether an abstract or alias has a binding. */
    public function bound(string $abstract): bool
    {
        return $this->has($abstract);
    }

    /** Whether an abstract has already been resolved and cached. */
    public function resolved(string $abstract): bool
    {
        return isset($this->resolved[$this->normalizeAbstract($abstract)]);
    }

    /** Whether an abstract is bound as a singleton or a stored instance. */
    public function isShared(string $abstract): bool
    {
        $abstract = $this->normalizeAbstract($abstract);

        return isset($this->resolved[$abstract])
            || ($this->services[$abstract]['singleton'] ?? false);
    }

    public function isAlias(string $name): bool
    {
        return isset($this->aliasTargets[$name]);
    }

    /** The abstract an alias points at, or the name itself when it is not one. */
    public function getAlias(string $abstract): string
    {
        return $this->aliasTargets[$abstract] ?? $abstract;
    }

    /** @return array<string, array<string, mixed>> */
    public function getBindings(): array
    {
        return $this->services;
    }

    /**
     * Bind only when nothing is bound yet.
     *
     * Lets a package supply a default without overriding an application that
     * has already made its own choice.
     */
    public function bindIf(string $abstract, mixed $concrete = null, bool $singleton = false): void
    {
        if (! $this->bound($abstract)) {
            $this->bind($abstract, $concrete ?? $abstract, $singleton);
        }
    }

    /** Register a singleton only when nothing is bound yet. */
    public function singletonIf(string $abstract, mixed $concrete = null): void
    {
        if (! $this->bound($abstract)) {
            $this->singleton($abstract, $concrete);
        }
    }

    /** Register a request-scoped binding only when nothing is bound yet. */
    public function scopedIf(string $abstract, mixed $concrete = null): void
    {
        if (! $this->bound($abstract)) {
            $this->scoped($abstract, $concrete);
        }
    }

    /**
     * Begin a contextual binding: what one consumer should be given for a
     * dependency, in place of the usual binding.
     *
     *   $container->when(ReportController::class)
     *             ->needs(Filesystem::class)
     *             ->give(fn () => Storage::disk('reports'));
     *
     * @param string|array<int, string> $concrete One consumer, or several.
     */
    public function when(string|array $concrete): ContextualBindingBuilder
    {
        return new ContextualBindingBuilder($this, (array) $concrete);
    }

    /**
     * Record a contextual binding without the fluent builder.
     *
     * @param string|array<int, string> $concrete
     */
    public function addContextualBinding(
        string|array $concrete,
        string $abstract,
        mixed $implementation
    ): void {
        foreach ((array) $concrete as $consumer) {
            $this->contextual($consumer, $abstract, $implementation);
        }
    }

    /**
     * Resolve an abstract with explicit constructor parameters.
     *
     * @param array<string, mixed> $parameters
     */
    public function makeWith(string $abstract, array $parameters = []): mixed
    {
        return $this->resolve($abstract, $parameters);
    }

    /** Drop a cached instance, so the next resolution builds a fresh one. */
    public function forgetInstance(string $abstract): void
    {
        unset($this->resolved[$this->normalizeAbstract($abstract)]);
    }

    /** Drop every cached instance, keeping the bindings themselves. */
    public function forgetInstances(): void
    {
        $this->resolved = [];
    }

    /** Drop everything: bindings, instances, aliases, hooks and tags. */
    public function flush(): void
    {
        $this->services = [];
        $this->resolved = [];
        $this->aliasTargets = [];
        $this->contextualBindings = [];
        $this->scopedInstances = [];
        $this->tags = [];
        $this->methodBindings = [];

        $this->flushCallbacks();
    }

    /**
     * Bind a callback to "Class@method", used in place of calling the method
     * itself when it is invoked through {@see call()}.
     *
     * @param string|array{0: string, 1: string} $method
     */
    public function bindMethod(string|array $method, Closure $callback): void
    {
        $this->methodBindings[$this->parseMethodBinding($method)] = $callback;
    }

    /** @param string|array{0: string, 1: string} $method */
    public function hasMethodBinding(string|array $method): bool
    {
        return isset($this->methodBindings[$this->parseMethodBinding($method)]);
    }

    /**
     * Invoke the callback bound to a method.
     *
     * @param string|array{0: string, 1: string} $method
     */
    public function callMethodBinding(string|array $method, mixed $instance): mixed
    {
        $key = $this->parseMethodBinding($method);

        if (! isset($this->methodBindings[$key])) {
            return null;
        }

        return $this->methodBindings[$key]($instance, $this);
    }

    /**
     * A closure that calls $callable through the container when invoked.
     *
     * @param array<string, mixed> $parameters
     */
    public function wrap(callable $callable, array $parameters = []): Closure
    {
        return fn () => $this->call($callable, $parameters);
    }

    /** @param string|array{0: string, 1: string} $method */
    private function parseMethodBinding(string|array $method): string
    {
        if (is_array($method)) {
            $class = is_object($method[0]) ? $method[0]::class : (string) $method[0];

            return $class . '@' . $method[1];
        }

        return $method;
    }

    // ─── ArrayAccess ──────────────────────────────────────────────────────

    public function offsetExists(mixed $offset): bool
    {
        return $this->bound((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->resolve((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->bind(
            (string) $offset,
            $value instanceof Closure ? $value : static fn () => $value
        );
    }

    public function offsetUnset(mixed $offset): void
    {
        $offset = (string) $offset;

        unset($this->services[$offset], $this->resolved[$offset]);
    }
}
