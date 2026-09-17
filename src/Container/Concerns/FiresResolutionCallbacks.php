<?php

namespace Nitro\Container\Concerns;

use Closure;

/**
 * Resolution hooks and service decoration for the container.
 *
 * Every hook here is opt-in and most applications register none, so the
 * resolution path must not pay for them. One boolean, {@see $hasCallbacks},
 * guards the whole mechanism: it stays false until something is registered, and
 * while false the container skips the callback lookup entirely rather than
 * walking empty arrays on every resolve.
 */
trait FiresResolutionCallbacks
{
    /**
     * Whether any resolution callback or extender exists.
     *
     * Read on every resolution, so it is a plain bool rather than a count of
     * several arrays.
     */
    private bool $hasCallbacks = false;

    /**
     * Whether any callback is registered against every abstract rather than a
     * named one. Globals have to run for each resolution; per-abstract
     * callbacks do not.
     */
    private bool $hasGlobalCallbacks = false;

    /**
     * Abstracts that have at least one callback or extender, as
     * [abstract => true].
     *
     * Registering a hook for one service should not slow down resolving every
     * other one. With no globals, firing collapses to a single isset() against
     * this map instead of five array lookups per class in the graph — which, in
     * an auto-wired tree, is paid once per constructor argument.
     */
    private array $abstractsWithCallbacks = [];

    /** @var array<string, array<int, Closure>> */
    private array $beforeResolvingCallbacks = [];

    /** @var array<string, array<int, Closure>> */
    private array $resolvingCallbacks = [];

    /** @var array<string, array<int, Closure>> */
    private array $afterResolvingCallbacks = [];

    /** @var array<int, Closure> */
    private array $globalBeforeResolvingCallbacks = [];

    /** @var array<int, Closure> */
    private array $globalResolvingCallbacks = [];

    /** @var array<int, Closure> */
    private array $globalAfterResolvingCallbacks = [];

    /** @var array<string, array<int, Closure>> */
    private array $extenders = [];

    /** @var array<string, array<int, Closure>> */
    private array $reboundCallbacks = [];

    /**
     * Run a callback before an abstract is resolved.
     *
     * Called with the abstract name and any parameters, before the instance is
     * built — the place to swap what is about to be made.
     */
    public function beforeResolving(Closure|string $abstract, ?Closure $callback = null): void
    {
        $this->hasCallbacks = true;

        if ($abstract instanceof Closure) {
            $this->hasGlobalCallbacks = true;
            $this->globalBeforeResolvingCallbacks[] = $abstract;
            return;
        }

        if ($callback !== null) {
            $abstract = $this->normalizeAbstract($abstract);
            $this->beforeResolvingCallbacks[$abstract][] = $callback;
            $this->abstractsWithCallbacks[$abstract] = true;
        }
    }

    /**
     * Run a callback when an abstract is resolved, before it is returned.
     *
     * Called with the instance and the container.
     */
    public function resolving(Closure|string $abstract, ?Closure $callback = null): void
    {
        $this->hasCallbacks = true;

        if ($abstract instanceof Closure) {
            $this->hasGlobalCallbacks = true;
            $this->globalResolvingCallbacks[] = $abstract;
            return;
        }

        if ($callback !== null) {
            $abstract = $this->normalizeAbstract($abstract);
            $this->resolvingCallbacks[$abstract][] = $callback;
            $this->abstractsWithCallbacks[$abstract] = true;
        }
    }

    /** Run a callback after the resolving callbacks have run. */
    public function afterResolving(Closure|string $abstract, ?Closure $callback = null): void
    {
        $this->hasCallbacks = true;

        if ($abstract instanceof Closure) {
            $this->hasGlobalCallbacks = true;
            $this->globalAfterResolvingCallbacks[] = $abstract;
            return;
        }

        if ($callback !== null) {
            $abstract = $this->normalizeAbstract($abstract);
            $this->afterResolvingCallbacks[$abstract][] = $callback;
            $this->abstractsWithCallbacks[$abstract] = true;
        }
    }

    /**
     * Wrap a resolved service in a decorator.
     *
     * The closure receives the instance and the container and returns what the
     * container should hand out instead. An abstract already resolved as a
     * singleton is extended immediately and its cached instance replaced, so
     * registration order does not decide whether the extender applies.
     */
    public function extend(string $abstract, Closure $extender): void
    {
        $this->hasCallbacks = true;

        $abstract = $this->normalizeAbstract($abstract);

        if (isset($this->resolved[$abstract])) {
            $this->resolved[$abstract] = $extender($this->resolved[$abstract], $this);
            $this->fireRebound($abstract, $this->resolved[$abstract]);

            return;
        }

        $this->extenders[$abstract][] = $extender;
        $this->abstractsWithCallbacks[$abstract] = true;
    }

    /** Drop the extenders registered for an abstract. */
    public function forgetExtenders(string $abstract): void
    {
        unset($this->extenders[$this->normalizeAbstract($abstract)]);
    }

    /**
     * Run a callback whenever an abstract is re-bound.
     *
     * Returns the currently bound instance, if there is one, so a caller can
     * both register interest and take the present value in one step.
     */
    public function rebinding(string $abstract, Closure $callback): mixed
    {
        $abstract = $this->normalizeAbstract($abstract);

        $this->reboundCallbacks[$abstract][] = $callback;

        return $this->bound($abstract) ? $this->make($abstract) : null;
    }

    /**
     * Re-resolve an abstract and push it into a target's setter.
     *
     * Used where a long-lived object holds a dependency that may be swapped
     * later: the target is updated in place whenever the binding changes.
     */
    public function refresh(string $abstract, mixed $target, string $method): mixed
    {
        return $this->rebinding(
            $abstract,
            static function ($instance) use ($target, $method): void {
                $target->{$method}($instance);
            }
        );
    }

    /** Notify anything registered through rebinding() that a binding changed. */
    protected function fireRebound(string $abstract, mixed $instance = null): void
    {
        if ($this->reboundCallbacks === []) {
            return;
        }

        $abstract = $this->normalizeAbstract($abstract);

        foreach ($this->reboundCallbacks[$abstract] ?? [] as $callback) {
            $callback($instance ?? $this->make($abstract), $this);
        }
    }

    /**
     * Run the before-resolving hooks for an abstract.
     *
     * @param array<string, mixed> $parameters
     */
    protected function fireBeforeResolving(string $abstract, array $parameters = []): void
    {
        if (! $this->hasGlobalCallbacks && ! isset($this->abstractsWithCallbacks[$abstract])) {
            return;
        }

        foreach ($this->globalBeforeResolvingCallbacks as $callback) {
            $callback($abstract, $parameters, $this);
        }

        foreach ($this->beforeResolvingCallbacks[$abstract] ?? [] as $callback) {
            $callback($abstract, $parameters, $this);
        }
    }

    /**
     * Apply extenders and run the resolving hooks for a freshly built instance.
     *
     * Extenders run first: a resolving callback should see the object the
     * caller will actually receive, decoration included.
     */
    protected function fireResolved(string $abstract, mixed $instance): mixed
    {
        if (! $this->hasGlobalCallbacks && ! isset($this->abstractsWithCallbacks[$abstract])) {
            return $instance;
        }

        foreach ($this->extenders[$abstract] ?? [] as $extender) {
            $instance = $extender($instance, $this);
        }

        foreach ($this->globalResolvingCallbacks as $callback) {
            $callback($instance, $this);
        }

        foreach ($this->resolvingCallbacks[$abstract] ?? [] as $callback) {
            $callback($instance, $this);
        }

        foreach ($this->globalAfterResolvingCallbacks as $callback) {
            $callback($instance, $this);
        }

        foreach ($this->afterResolvingCallbacks[$abstract] ?? [] as $callback) {
            $callback($instance, $this);
        }

        return $instance;
    }

    /**
     * Whether anything would run for this abstract.
     *
     * Three property reads and no array walking, so a resolution path can ask
     * before paying for a call into the callback machinery.
     */
    protected function shouldFireCallbacks(string $abstract): bool
    {
        return $this->hasCallbacks
            && ($this->hasGlobalCallbacks || isset($this->abstractsWithCallbacks[$abstract]));
    }

    /** Whether any hook or extender has been registered. */
    protected function hasResolutionCallbacks(): bool
    {
        return $this->hasCallbacks;
    }

    /** Drop every registered hook and extender. */
    protected function flushCallbacks(): void
    {
        $this->hasCallbacks = false;
        $this->hasGlobalCallbacks = false;
        $this->abstractsWithCallbacks = [];
        $this->beforeResolvingCallbacks = [];
        $this->resolvingCallbacks = [];
        $this->afterResolvingCallbacks = [];
        $this->globalBeforeResolvingCallbacks = [];
        $this->globalResolvingCallbacks = [];
        $this->globalAfterResolvingCallbacks = [];
        $this->extenders = [];
        $this->reboundCallbacks = [];
    }

    /** Resolve an alias to the abstract callbacks are keyed under. */
    abstract protected function normalizeAbstract(string $abstract): string;
}
