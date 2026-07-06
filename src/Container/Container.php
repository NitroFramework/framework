<?php

namespace Nitro\Container;

use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Container\Contracts\ProfilerInterface;
use Nitro\Container\Exceptions\NotFoundException;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * The Nitro service container — a reflection-based dependency-injection container.
 *
 * Holds service definitions (closures, class-strings, or pre-built instances) and
 * resolves them on demand, auto-wiring constructor dependencies via cached
 * reflection. Supports singletons, request-scoped bindings (flushed between worker
 * requests), factories, aliases, contextual and tagged bindings, lazy deferred-
 * provider registration, and route-model parameter binding. Profiling is an opt-in
 * dev concern attached via setProfiler() — the core carries no debug logic itself.
 */
class Container implements ContainerInterface
{

    // Attached only in debug (by the front controllers); null means no profiling
    // and thus zero cost — the core never references a concrete profiler.
    private ?ProfilerInterface $profiler = null;
    private array $tags = [];
    private array $contextualBindings = [];
    private array $building = [];

    /**
     * Map of alias => target abstract, recorded by alias(). Lets forgetScoped()
     * clear the instance an alias actually resolves to (cached under the target
     * name), not just the alias key — see forgetScoped().
     */
    private array $aliasTargets = [];

    /**
     * Abstracts registered as request-scoped via scoped(). Unlike the central
     * WorkerMode list, a feature declares its own bindings scoped at register
     * time; forgetScopedInstances() flushes them all between worker requests.
     * (Same model as Laravel Octane's scoped instances.)
     */
    private array $scopedInstances = [];

    /**
     * Callback invoked when an unregistered abstract is requested. Used by
     * Application to lazy-register deferred service providers — when their
     * service is finally asked for, we call them in, register their bindings,
     * and resolve as normal.
     *
     * Signature: fn(string $abstract): bool — returns true if the abstract is
     * now registered (so the caller can retry the lookup).
     */
    private ?\Closure $deferredResolver = null;

    /**
     * Sentinel returned by a parameter binder to mean "I don't handle this
     * type" — the resolver then falls through to its normal logic. Null-byte
     * prefixed so it can never collide with a real resolved value.
     */
    public const PARAM_UNRESOLVED = "\0nitro:param-unresolved";

    /**
     * Optional resolver that turns a typed parameter + a matching scalar route
     * value into an object (route-model binding). Registered by the routing
     * layer so the core container stays free of any model/HTTP dependency.
     * Signature: fn(string $typeName, mixed $scalar): mixed — return
     * PARAM_UNRESOLVED to decline.
     */
    private ?\Closure $parameterBinder = null;

    /**
     * Per-class reflection cache. Each entry is:
     *   [
     *     'class'   => ReflectionClass,
     *     'instantiable' => bool,
     *     'ctor'    => ReflectionMethod|null,
     *     'params'  => ReflectionParameter[],   // empty when ctor is null
     *   ]
     *
     * Populated lazily by build() so each class pays the reflection cost
     * exactly once for the lifetime of the container instance.
     */
    private array $reflectionCache = [];

    // ============================================
    // SINGLETON
    // ============================================

    private static ?self $instance = null;

    /** Public so tests can create isolated containers instead of the shared singleton. */
    public function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Attach a profiler (or detach with null). The container records its activity
     * to whatever is attached; with none attached there is no profiling cost. The
     * front controllers attach one in debug mode. The container depends only on
     * the ProfilerInterface — never on a concrete profiler or the dev tooling.
     */
    public function setProfiler(?ProfilerInterface $profiler): void
    {
        $this->profiler = $profiler;
    }

    // ============================================
    // REGISTRY
    // Stores and retrieves services by name
    // ============================================

    /** Registered service definitions */
    private array $services = [];

    /** Resolved singleton instances */
    private array $resolved = [];

    /** Bind a service or value */
    public function bind(string $name, $value, bool $singleton = true): void
    {
        $this->services[$name] = [
            'value'     => $value,
            'singleton' => $singleton,
        ];
        unset($this->resolved[$name]);
    }

    /**
     * Register a request-scoped service: resolved once like a singleton, but
     * flushed by forgetScopedInstances() between worker requests. The binding
     * declares its own lifecycle — no central reset list to edit per feature.
     */
    public function scoped(string $name, $value = null): void
    {
        if (!in_array($name, $this->scopedInstances, true)) {
            $this->scopedInstances[] = $name;
        }
        $this->singleton($name, $value);
    }

    /**
     * Drop the resolved instance of every scoped binding (and any alias target
     * it resolves to) so the next worker request rebuilds them fresh.
     */
    public function forgetScopedInstances(): void
    {
        foreach ($this->scopedInstances as $name) {
            unset($this->resolved[$name]);
            if (isset($this->aliasTargets[$name])) {
                unset($this->resolved[$this->aliasTargets[$name]]);
            }
        }
    }

    /** Register a singleton service */
    public function singleton(string $name, $value = null): void
    {
        $this->bind($name, $value ?? $name, true);

        $this->profiler?->recordRegistration($name);
    }

    /** Register an already-created instance */
    public function instance(string $name, mixed $instance): void
    {
        $this->resolved[$name] = $instance;
        $this->services[$name] = [
            'value'     => $instance,
            'singleton' => true,
        ];

        $this->profiler?->recordInstance($name);
    }

    /** Register a factory (non-singleton) */
    public function factory(string $name, callable $factory): void
    {
        $this->bind($name, $factory, false);
    }

    /**
     * Register a resolver that gets a chance to register a service on demand
     * when get()/make() can't find it. Used by the deferred-provider path.
     */
    public function setDeferredResolver(\Closure $resolver): void
    {
        $this->deferredResolver = $resolver;
    }

    /**
     * Register the parameter binder used for route-model binding. See
     * {@see $parameterBinder}.
     */
    public function bindParametersUsing(\Closure $resolver): void
    {
        $this->parameterBinder = $resolver;
    }

    /** Get a registered service by name */
    public function get(string $name): mixed
    {
        if (!$this->has($name)) {
            if ($this->deferredResolver !== null && ($this->deferredResolver)($name)) {
                // Provider registered the binding lazily — fall through.
            } else {
                throw new NotFoundException("Service [{$name}] not found in container");
            }
        }

        $service = $this->services[$name];

        if ($service['singleton'] && isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $profilerId = $this->profiler?->startResolving($name, 'get');

        $resolved = $this->resolveValue($service['value']);

        if ($service['singleton']) {
            $this->resolved[$name] = $resolved;
        }

        if ($profilerId !== null) {
            $this->profiler?->endResolving($profilerId, 'closure');
        }

        return $resolved;
    }

    /** Check if a service is registered */
    public function has(string $name): bool
    {
        return isset($this->services[$name]);
    }

    /** Get a service or return default if not found */
    public function getOrDefault(string $name, $default = null): mixed
    {
        if (!$this->has($name)) {
            return $default;
        }
        return $this->get($name);
    }

    /** Remove a service from the registry */
    public function forget(string $name): void
    {
        unset($this->services[$name]);
        unset($this->resolved[$name]);
        unset($this->aliasTargets[$name]);
    }

    /**
     * Drop the RESOLVED instance for each scoped service so the next request
     * gets a fresh one, while keeping the binding in place so provider
     * singletons can re-instantiate without re-running their providers.
     *
     * For services bound via instance() (e.g. the current Request), the Kernel
     * re-binds via instance() at the start of the next request — clearing
     * resolved is enough because that re-binding overwrites both arrays.
     */
    public function forgetScoped(array $names): void
    {
        foreach ($names as $name) {
            unset($this->resolved[$name]);

            // Aliased scoped services (e.g. 'auth' → SessionGuard) cache their
            // resolved instance under the TARGET class name, not the alias key.
            // Clear that too — otherwise the underlying singleton survives the
            // reset and leaks request state across worker iterations.
            if (isset($this->aliasTargets[$name])) {
                unset($this->resolved[$this->aliasTargets[$name]]);
            }
        }
    }

    /**
     * Drop both the resolved instance AND the binding. Useful when the caller
     * wants has() to return false until something re-binds.
     */
    public function forgetScopedHard(array $names): void
    {
        foreach ($names as $name) {
            unset($this->resolved[$name]);
            unset($this->services[$name]);
        }
    }

    /** Resolve a raw value — executes closures, returns everything else as-is */
    private function resolveValue($value): mixed
    {
        if ($value instanceof \Closure) {
            return $value($this);
        }

        if (is_string($value) && class_exists($value)) {
            // Prevent infinite loop: if we're already resolving this class, just build it directly
            if (isset($this->building[$value])) {
                return $this->build($value);
            }

            $this->building[$value] = true;
            try {
                return $this->make($value);
            } finally {
                unset($this->building[$value]);
            }
        }

        return $value;
    }

    // ============================================
    // AUTO-WIRING
    // Resolves class dependencies via reflection
    // ============================================

    /** Classes currently being resolved — used to detect circular dependencies */
    private array $resolving = [];

    /**
     * Make a class instance, auto-wiring all constructor dependencies.
     * If registered in the container, returns that. Otherwise reflects and builds.
     *
     * Only delegates to get() when no $parameters are passed, so explicit
     * overrides are never silently dropped.
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        if (empty($parameters)) {
            if ($this->has($abstract)) {
                return $this->get($abstract);
            }
            // Give a deferred-provider resolver a chance to register this
            // abstract before we fall back to auto-wiring.
            if ($this->deferredResolver !== null && ($this->deferredResolver)($abstract)) {
                return $this->get($abstract);
            }
        }

        return $this->build($abstract, $parameters);
    }

    /** Build a class via reflection, resolving all constructor dependencies recursively */
    private function build(string $abstract, array $parameters = []): mixed
    {
        $profilerId = $this->profiler?->startResolving($abstract, 'build');

        if (isset($this->resolving[$abstract])) {
            $chain = implode(' → ', array_keys($this->resolving)) . ' → ' . $abstract;
            throw new RuntimeException("Circular dependency detected: {$chain}");
        }

        $meta = $this->reflectionMetaFor($abstract);

        if (!$meta['instantiable']) {
            throw new RuntimeException("Class [{$abstract}] is not instantiable. Is it an interface or abstract class?");
        }

        if ($meta['ctor'] === null) {
            // Cheaper than newInstanceArgs([]) — and matches the original
            // fast-path for parameterless classes. Close the profiler span the
            // top of this method opened so it isn't left dangling.
            if ($profilerId !== null) {
                $this->profiler?->endResolving($profilerId, 'class');
            }
            return new $abstract();
        }

        $this->resolving[$abstract] = true;

        try {
            $dependencies = $this->resolveDependencies($abstract, $meta['params'], $parameters);
            $instance = $meta['class']->newInstanceArgs($dependencies);
        } finally {
            unset($this->resolving[$abstract]);

            if ($profilerId !== null) {
                $this->profiler?->endResolving($profilerId, 'class');
            }
        }

        return $instance;
    }

    /**
     * Return cached reflection metadata for a class, populating the cache on
     * first call. Each class then pays the reflection cost exactly once.
     *
     * @return array{class: ReflectionClass, instantiable: bool, ctor: \ReflectionMethod|null, params: array}
     */
    private function reflectionMetaFor(string $abstract): array
    {
        if (isset($this->reflectionCache[$abstract])) {
            return $this->reflectionCache[$abstract];
        }

        try {
            $normalized = ltrim($abstract, '\\');
            $reflector = new ReflectionClass($normalized);
        } catch (\ReflectionException $e) {
            throw new RuntimeException("Class [{$abstract}] does not exist. (Tried resolving as [{$normalized}])");
        }

        $ctor = $reflector->getConstructor();

        return $this->reflectionCache[$abstract] = [
            'class'        => $reflector,
            'instantiable' => $reflector->isInstantiable(),
            'ctor'         => $ctor,
            'params'       => $ctor ? $ctor->getParameters() : [],
        ];
    }

    /** Drop the reflection cache (test harnesses, opcache reset on deploy). */
    public function clearReflectionCache(): void
    {
        $this->reflectionCache = [];
    }

    /**
     * Resolve constructor parameters — auto-wires classes, handles primitives and defaults.
     *
     * Resolution order per parameter: contextual binding (by param name, then by
     * type) → named override → positional override → auto-wired class → default
     * value. Contextual bindings are keyed by both parameter name and type name.
     *
     * @param string|null $consumer The class being built (null when called from call())
     */
    private function resolveDependencies(?string $consumer, array $reflectionParams, array $primitives = []): array
    {
        $dependencies = [];
        $numericIndex = 0;

        foreach ($reflectionParams as $param) {
            $name = $param->getName();
            $type = $param->getType();

            // getDeclaringClass() returns null for closures passed to call()
            $declaringClass = $param->getDeclaringClass();
            $class = $declaringClass ? $declaringClass->getName() : null;

            // The consumer is the class we're building — prefer it over declaring class
            // (declaring class may be a parent, consumer is the actual concrete)
            $contextKey = $consumer ?? $class;

            $typeName = ($type instanceof ReflectionNamedType && !$type->isBuiltin())
                ? $type->getName()
                : null;

            // -------------------------------------------------------
            // 1. Contextual bindings (checked first)
            //    Check by parameter name, then by type name
            // -------------------------------------------------------
            if ($contextKey !== null) {
                // Check by parameter name: contextual('Controller', 'logger', SpecialLogger::class)
                if (isset($this->contextualBindings[$contextKey][$name])) {
                    $value = $this->contextualBindings[$contextKey][$name];
                    $dependencies[] = $value instanceof \Closure ? $value($this) : $this->resolveContextualValue($value);
                    continue;
                }

                // Check by type/interface name: contextual('Controller', LoggerInterface::class, FileLogger::class)
                if ($typeName !== null && isset($this->contextualBindings[$contextKey][$typeName])) {
                    $value = $this->contextualBindings[$contextKey][$typeName];
                    $dependencies[] = $value instanceof \Closure ? $value($this) : $this->resolveContextualValue($value);
                    continue;
                }
            }

            // -------------------------------------------------------
            // 1b. Route-model binding: a typed (class) parameter whose name
            //     matches a scalar route value is handed to the binder, which
            //     may resolve it to an object (e.g. Model::find()). Declining
            //     (PARAM_UNRESOLVED) falls through to the scalar override below.
            // -------------------------------------------------------
            if (
                $typeName !== null
                && $this->parameterBinder !== null
                && array_key_exists($name, $primitives)
                && is_scalar($primitives[$name])
            ) {
                $bound = ($this->parameterBinder)($typeName, $primitives[$name]);
                if ($bound !== self::PARAM_UNRESOLVED) {
                    $dependencies[] = $bound;
                    continue;
                }
            }

            // -------------------------------------------------------
            // 2. Named overrides (explicit parameters passed to make/build)
            // -------------------------------------------------------
            if (array_key_exists($name, $primitives)) {
                $dependencies[] = $primitives[$name];
                continue;
            }

            // -------------------------------------------------------
            // 3. Numeric overrides (e.g. route params)
            // -------------------------------------------------------
            if (array_key_exists($numericIndex, $primitives)) {
                $dependencies[] = $primitives[$numericIndex];
                $numericIndex++;
                continue;
            }

            // -------------------------------------------------------
            // 4. Auto-wire class/interface types
            // -------------------------------------------------------
            if ($typeName !== null) {
                // Closure can't be auto-wired
                if ($typeName === 'Closure') {
                    if ($param->isDefaultValueAvailable()) {
                        $dependencies[] = $param->getDefaultValue();
                        continue;
                    }
                    throw new RuntimeException(
                        "Cannot auto-wire Closure parameter [\${$name}]" .
                        ($class ? " in [{$class}]" : '') .
                        ". Use a factory binding."
                    );
                }

                $dependencies[] = $this->make($typeName);
                continue;
            }

            // -------------------------------------------------------
            // 5. Default values
            // -------------------------------------------------------
            if ($param->isDefaultValueAvailable()) {
                $dependencies[] = $param->getDefaultValue();
                continue;
            }

            throw new RuntimeException(
                "Cannot resolve parameter [{$name}]" .
                ($class ? " in [{$class}]" : '') .
                " — no rule defined."
            );
        }

        return $dependencies;
    }

    /**
     * Resolve a contextual binding value.
     * If it's a class string, make it. Otherwise return as-is.
     */
    private function resolveContextualValue(mixed $value): mixed
    {
        if (is_string($value) && class_exists($value)) {
            return $this->make($value);
        }
        return $value;
    }

    // ============================================
    // DEBUGGING
    // ============================================

    /** Get all registered service names */
    public function getServiceNames(): array
    {
        return array_keys($this->services);
    }

    /** Get all currently resolved instances */
    public function getResolvedInstances(): array
    {
        return $this->resolved;
    }

    /** Force-resolve all services and return instances */
    public function resolveAllForDebug(): array
    {
        foreach ($this->services as $name => $service) {
            $this->get($name);
        }
        return $this->resolved;
    }

    /**
     * Return structured constructor-introspection data for an abstract
     * (for debugging container resolution); never echoes or dies.
     */
    public function debugReflection(string $abstract): array
    {
        try {
            $reflector = new ReflectionClass($abstract);
            $constructor = $reflector->getConstructor();

            $info = [
                'class' => $abstract,
                'file'  => $reflector->getFileName(),
                'parameters' => [],
            ];

            if (!$constructor) {
                return $info;
            }

            foreach ($constructor->getParameters() as $param) {
                $type = $param->getType();
                $paramInfo = [
                    'name'       => $param->getName(),
                    'type_class' => $type ? get_class($type) : null,
                    'type_name'  => null,
                    'is_builtin' => null,
                    'has_default' => $param->isDefaultValueAvailable(),
                ];

                if ($type instanceof ReflectionNamedType) {
                    $paramInfo['type_name'] = $type->getName();
                    $paramInfo['is_builtin'] = $type->isBuiltin();
                }

                $info['parameters'][] = $paramInfo;
            }

            return $info;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    // ============================================
    // MAGIC ACCESS
    // ============================================

    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    public function __isset(string $name): bool
    {
        return $this->has($name);
    }

    public function tag(string $tag, string ...$abstracts): void
    {
        foreach ($abstracts as $abstract) {
            $this->tags[$tag][] = $abstract;
        }
    }

    public function tagged(string $tag): array
    {
        if (!isset($this->tags[$tag])) {
            return [];
        }
        $result = [];
        foreach ($this->tags[$tag] as $abstract) {
            $result[] = $this->make($abstract);
        }
        return $result;
    }

    /**
     * Register a contextual binding.
     *
     * $needed can be a parameter name OR an interface/class name.
     * Examples:
     *   contextual(Controller::class, LoggerInterface::class, FileLogger::class)
     *   contextual(Controller::class, 'diskPath', '/tmp/uploads')
     */
    public function contextual(string $needer, string $needed, string|callable $concrete): void
    {
        $this->contextualBindings[$needer][$needed] = $concrete;
    }

    /**
     * Invoke a callable (closure or [object, method]), auto-wiring its
     * parameters. Passes null as consumer since call() operates on
     * closures/methods, not class construction.
     */
    public function call(callable $callable, array $parameters = []): mixed
    {
        if (is_array($callable)) {
            [$object, $method] = $callable;
            $reflector = new \ReflectionMethod($object, $method);
        } else {
            $reflector = new \ReflectionFunction($callable);
        }

        $dependencies = $this->resolveDependencies(null, $reflector->getParameters(), $parameters);

        if (is_array($callable)) {
            return $reflector->invokeArgs($callable[0], $dependencies);
        }

        return $reflector->invokeArgs($dependencies);
    }

    /** Register an alias that resolves to an existing binding */
    public function alias(string $alias, string $abstract): void
    {
        $this->aliasTargets[$alias] = $abstract;
        $this->services[$alias] = [
            'value'     => fn($c) => $c->get($abstract),
            'singleton' => true,
        ];
    }
}