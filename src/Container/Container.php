<?php

namespace Nitro\Container;

use Closure;
use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Contracts\Container\BindingResolutionException as IlluminateBindingResolutionException;
use Illuminate\Contracts\Container\Container as IlluminateContainerContract;
use Nitro\Container\Contracts\CallableInvoker;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Container\Exceptions\BindingResolutionException;
use Nitro\Container\Exceptions\NotFoundException;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * The framework's service container.
 *
 * Resolves a service by name or by class, building anything it has no binding
 * for from the types its constructor declares. A binding is transient, shared
 * for the process, or shared for one request; it may be registered up front by
 * a provider or produced on demand by a deferred one.
 *
 * Constructor arguments may be supplied by name or by position, and a resolved
 * name may be aliased, tagged, extended, or bound differently for one consumer
 * than for another. Two narrower views of the container are registered for a
 * class that needs one thing from it rather than all of it: {@see ClassResolver}
 * to build a type, {@see CallableInvoker} to call a method.
 *
 * @see ContainerInterface The contract to type a consumer against.
 */
class Container extends IlluminateContainer implements ContainerInterface
{
    /** Returned by a parameter binder that declines to bind a value. */
    public const PARAM_UNRESOLVED = "\0nitro:param-unresolved";

    /** Resolver given a chance to register a name nothing has bound yet. */
    private ?Closure $deferredResolver = null;

    /** Called with ($name, $object) for every object the container hands out. */
    private ?Closure $resolutionObserver = null;

    /**
     * Names whose binding is being run right now, outermost first.
     *
     * @var array<string, true>
     */
    private array $resolvingBindings = [];

    /** Public so tests can create isolated containers instead of the shared singleton. */
    public function __construct()
    {
        $this->registerCapabilities();
    }

    /**
     * Bind the two narrow views of the container: {@see ClassResolver} to
     * build a type, {@see CallableInvoker} to call a method.
     *
     * Registered here rather than by a provider so that any container offers
     * them, including one with nothing else in it.
     */
    protected function registerCapabilities(): void
    {
        $this->singleton(ClassResolver::class, static fn (self $container): ClassResolver
            => new ContainerClassResolver($container));

        $this->singleton(CallableInvoker::class, static fn (self $container): CallableInvoker
            => new ContainerCallableInvoker($container));
    }

    // ============================================
    // SHARED INSTANCE
    // ============================================

    /**
     * The container the application established.
     *
     * Does not create one: a container invented here is empty, and the error
     * surfaces later as "service not found" somewhere unrelated.
     *
     * @throws RuntimeException When no container has been established.
     */
    public static function getInstance()
    {
        if (static::$instance === null) {
            throw new RuntimeException(
                'No container has been established, so there is no application to resolve from. '
                . 'This is usually app(), a facade, or Container::getInstance() being reached before '
                . 'the application booted — or after Container::reset(). A host that owns its own '
                . 'container establishes it with Container::setInstance(new Container()).'
            );
        }

        return static::$instance;
    }

    /** Whether a shared container exists, without creating or demanding one. */
    public static function hasInstance(): bool
    {
        return static::$instance !== null;
    }

    public static function reset(): void
    {
        static::$instance = null;
    }

    /**
     * Make this the container the helpers and facades resolve from, returning
     * the one it replaces.
     */
    public static function setInstance(?IlluminateContainerContract $container = null)
    {
        $previous = static::$instance;

        static::$instance = $container;

        return $previous;
    }

    // ============================================
    // REGISTRATION
    // ============================================

    /**
     * Register an already-created instance as the shared value for a name.
     *
     * @param  string $abstract
     * @param  mixed  $instance
     * @return mixed
     */
    public function instance($abstract, $instance)
    {
        $result = parent::instance($abstract, $instance);

        if ($this->resolutionObserver !== null) {
            ($this->resolutionObserver)($abstract, $instance);
        }

        return $result;
    }

    /**
     * Register an observer called with ($name, $object) for every object the
     * container hands out, whether resolved or registered as an instance.
     *
     * Null removes it. The container interprets nothing it reports.
     */
    public function observeResolutions(?Closure $observer): void
    {
        $this->resolutionObserver = $observer;
    }

    /**
     * Register a contextual binding by parameter name or by type name.
     *
     * A parameter may be named with or without its sigil — needs('$timeout')
     * reads as the parameter — while a type is named as it is written.
     */
    public function contextual(string $needer, string $needed, string|callable $concrete): void
    {
        $bare = ltrim($needed, '$');

        $this->addContextualBinding(
            $needer,
            interface_exists($bare) || class_exists($bare) ? $bare : '$' . $bare,
            $concrete
        );
    }

    /** Whether anything has been bound contextually for $consumer. */
    public function hasContextualBindings(string $consumer): bool
    {
        return ! empty($this->contextual[$consumer]);
    }

    /**
     * Wrap what $abstract resolves to, now and on every later resolution.
     *
     * The closure receives the object and the container and returns what
     * callers should get in its place. An instance that already exists is
     * wrapped immediately; the closure is kept either way, so a service
     * rebuilt after a worker reset is extended again.
     *
     * @param string  $abstract
     * @param Closure $closure
     */
    public function extend($abstract, Closure $closure)
    {
        $abstract = $this->getAlias($abstract);

        $this->extenders[$abstract][] = $closure;

        $live = isset($this->instances[$abstract]);

        if ($live) {
            $this->instances[$abstract] = $closure($this->instances[$abstract], $this);
        }

        if ($live || $this->resolved($abstract)) {
            $this->rebound($abstract);
        }
    }

    /**
     * Register a binding that runs in place of a method.
     *
     * @param string|array{0: object|string, 1: string} $method
     * @param Closure                                   $callback
     */
    public function bindMethod($method, $callback)
    {
        $this->methodBindings[$this->methodBindingKey($method)] = $callback;
    }

    /**
     * @param  string|array{0: object|string, 1: string} $method
     * @return bool
     */
    public function hasMethodBinding($method)
    {
        return isset($this->methodBindings[$this->methodBindingKey($method)]);
    }

    /**
     * @param  string|array{0: object|string, 1: string} $method
     * @param  mixed                                     $instance
     * @return mixed
     */
    public function callMethodBinding($method, $instance)
    {
        return call_user_func($this->methodBindings[$this->methodBindingKey($method)], $instance, $this);
    }

    /**
     * The "Class@method" key a method binding is stored under.
     *
     * A method may be named as that string or as an [object-or-class, method]
     * pair; the pair is what a caller dispatching a resolved instance has to
     * hand, and only the class part of it identifies the binding.
     *
     * @param string|array{0: object|string, 1: string} $method
     */
    private function methodBindingKey(string|array $method): string
    {
        if (! is_array($method)) {
            return $method;
        }

        return (is_object($method[0]) ? $method[0]::class : $method[0]) . '@' . $method[1];
    }

    /**
     * Register a resolver that gets a chance to register a service on demand
     * when resolution can't find one. Used by the deferred-provider path.
     */
    public function setDeferredResolver(Closure $resolver): void
    {
        $this->deferredResolver = $resolver;
    }

    // ============================================
    // RESOLUTION
    // ============================================

    /**
     * Resolve the registered binding for $abstract, or create it by auto-wiring
     * its constructor when nothing is bound.
     *
     * @param  string|callable $abstract
     * @param  array           $parameters
     * @param  bool            $raiseEvents
     * @return mixed
     */
    public function resolve($abstract, $parameters = [], $raiseEvents = true)
    {
        if (is_string($abstract)) {
            $this->loadDeferred($abstract);
        }

        if (! is_string($abstract) || ! $this->bound($abstract)) {
            return parent::resolve($abstract, $parameters, $raiseEvents);
        }

        $name = $this->getAlias($abstract);

        $this->enterBinding($name);

        try {
            $object = parent::resolve($abstract, $parameters, $raiseEvents);
        } finally {
            unset($this->resolvingBindings[$name]);
        }

        if ($this->resolutionObserver !== null) {
            ($this->resolutionObserver)($abstract, $object);
        }

        return $object;
    }

    /**
     * Ask the deferred resolver to register $abstract, if nothing has yet.
     *
     * An alias is asked about by the name it points at, since that is what a
     * provider binds, and then by the alias itself in case a provider claims
     * the short name instead.
     */
    private function loadDeferred(string $abstract): void
    {
        if ($this->deferredResolver === null) {
            return;
        }

        $target = $this->getAlias($abstract);

        if (isset($this->bindings[$target]) || isset($this->instances[$target])) {
            return;
        }

        ($this->deferredResolver)($target);

        if ($target !== $abstract && ! isset($this->bindings[$target]) && ! isset($this->instances[$target])) {
            ($this->deferredResolver)($abstract);
        }
    }

    /**
     * Build $concrete directly, ignoring any binding registered for it.
     *
     * $parameters are constructor overrides for this build alone, matched by
     * name or by position.
     *
     * @param  \Closure|string         $concrete
     * @param  array<array-key, mixed> $parameters
     * @return mixed
     *
     * @throws BindingResolutionException When the class is missing or cannot be built.
     */
    public function build($concrete, array $parameters = [])
    {
        if ($parameters !== []) {
            $this->with[] = $parameters;
        }

        try {
            return parent::build($concrete);
        } catch (BindingResolutionException $exception) {
            throw $exception;
        } catch (IlluminateBindingResolutionException $exception) {
            throw new BindingResolutionException(
                $exception->getMessage(),
                (int) $exception->getCode(),
                $exception->getPrevious()
            );
        } finally {
            if ($parameters !== []) {
                array_pop($this->with);
            }
        }
    }

    /**
     * @param  string $concrete
     * @return void
     *
     * @throws BindingResolutionException Always.
     */
    protected function notInstantiable($concrete)
    {
        throw new BindingResolutionException(
            $this->buildStack === []
                ? "Target [{$concrete}] is not instantiable."
                : "Target [{$concrete}] is not instantiable while building ["
                    . implode(', ', $this->buildStack) . '].'
        );
    }

    /**
     * Mark $name's binding as running, refusing one that already is.
     *
     * Guards bindings that resolve each other, which the reflective builder's
     * own cycle check never sees because it inspects classes rather than the
     * closures a binding is written as.
     *
     * @throws BindingResolutionException When the name is already resolving.
     */
    private function enterBinding(string $name): void
    {
        if (isset($this->resolvingBindings[$name])) {
            $chain = implode(' → ', array_keys($this->resolvingBindings)) . ' → ' . $name;

            $this->resolvingBindings = [];

            throw new BindingResolutionException(
                "Circular dependency resolving [{$name}]: {$chain}"
            );
        }

        $this->resolvingBindings[$name] = true;
    }

    /**
     * Strict registry lookup: resolve a registered binding, throwing
     * NotFoundException when there is none.
     *
     * @throws NotFoundException
     */
    public function get(string $id)
    {
        $this->loadDeferred($id);

        if (! $this->has($id)) {
            throw new NotFoundException("Service [{$id}] not found in container");
        }

        return $this->resolve($id);
    }

    /** Get a service or return default if not found. */
    public function getOrDefault(string $name, $default = null): mixed
    {
        return $this->has($name) ? $this->get($name) : $default;
    }

    /**
     * Work out what to pass a constructor.
     *
     * An override is matched to a parameter by its name, or by its position
     * when the caller knows the shape of the constructor but not what its
     * parameters are called. A variadic takes a named override spread out, or
     * every positional value not yet consumed. Anything left unmatched is
     * resolved from its declared type.
     *
     * @param  array<int, \ReflectionParameter> $dependencies
     * @return array<int, mixed>
     */
    protected function resolveDependencies(array $dependencies)
    {
        $overrides = $this->getLastParameterOverride();

        $positional = array_filter($overrides, 'is_int', ARRAY_FILTER_USE_KEY);

        $variadic = array_filter(
            $dependencies,
            static fn (\ReflectionParameter $dependency): bool => $dependency->isVariadic()
        );

        if ($positional === [] && $variadic === []) {
            return parent::resolveDependencies($dependencies);
        }

        $results = [];
        $position = 0;

        foreach ($dependencies as $dependency) {
            $name = $dependency->getName();

            if ($dependency->isVariadic()) {
                if (array_key_exists($name, $overrides)) {
                    array_push($results, ...array_values((array) $overrides[$name]));
                    break;
                }

                while (array_key_exists($position, $overrides)) {
                    $results[] = $overrides[$position++];
                }

                break;
            }

            if (array_key_exists($name, $overrides)) {
                $results[] = $overrides[$name];
                continue;
            }

            if (array_key_exists($position, $overrides)) {
                $results[] = $overrides[$position++];
                continue;
            }

            $results = array_merge($results, parent::resolveDependencies([$dependency]));
        }

        return $results;
    }

    // ============================================
    // LIFECYCLE
    // ============================================

    /** Remove a service from the registry, and everything the container knew about it. */
    public function forget(string $name): void
    {
        $this->forgetInstance($name);

        unset(
            $this->bindings[$name],
            $this->resolved[$name],
        );

        if (isset($this->aliases[$name])) {
            $this->removeAbstractAlias($name);
            unset($this->aliases[$name]);
        }

        unset($this->abstractAliases[$name]);

        $this->scopedInstances = array_values(array_diff($this->scopedInstances, [$name]));
    }

    /**
     * Every name bound with scoped(), which is to say every service that lives
     * for one request.
     *
     * Asked for rather than listed elsewhere: a service declares its lifetime
     * where it is registered, so anything that needs to know which those are
     * should read the answer here instead of keeping a list that a new binding
     * silently falls out of.
     *
     * @return array<int, string>
     */
    public function scopedNames(): array
    {
        return $this->scopedInstances;
    }

    /**
     * Drop the resolved instance for each named service so the next request
     * gets a fresh one, while keeping the binding in place.
     *
     * An aliased scoped service caches its resolved instance under the target
     * name, not the alias, so both are cleared.
     *
     * @param array<int, string> $names
     */
    public function forgetScoped(array $names): void
    {
        foreach ($names as $name) {
            unset($this->instances[$name], $this->instances[$this->getAlias($name)]);
        }
    }

    /**
     * Drop both the resolved instance and the binding, so has() returns false
     * until something re-binds.
     *
     * @param array<int, string> $names
     */
    public function forgetScopedHard(array $names): void
    {
        foreach ($names as $name) {
            unset($this->instances[$name], $this->bindings[$name]);
        }
    }

    /** Empty the container, then put its own capabilities back. */
    public function flush()
    {
        parent::flush();

        $this->registerCapabilities();
    }

    // ============================================
    // INTROSPECTION
    // ============================================

    /**
     * Every name the container answers to.
     *
     * @return array<int, string>
     */
    public function getServiceNames(): array
    {
        return array_values(array_unique(array_merge(
            array_keys($this->bindings),
            array_keys($this->instances),
            array_keys($this->aliases),
        )));
    }

    /**
     * Get all currently resolved instances.
     *
     * @return array<string, mixed>
     */
    public function getResolvedInstances(): array
    {
        return $this->instances;
    }

    /**
     * Every registered binding, as name => the value it was bound to.
     *
     * For tooling that inspects the container without resolving anything —
     * resolving is what an audit of the bindings is trying to avoid, since a
     * factory may open a connection or read the request.
     *
     * @return array<string, mixed>
     */
    public function registeredBindings(): array
    {
        return array_map(static fn (array $binding): mixed => $binding['concrete'], $this->bindings);
    }

    /**
     * Alias name => the abstract it resolves to.
     *
     * @return array<string, string>
     */
    public function registeredAliases(): array
    {
        return $this->aliases;
    }

    /**
     * Force-resolve every binding and return the instances.
     *
     * @return array<string, mixed>
     */
    public function resolveAllForDebug(): array
    {
        foreach (array_keys($this->bindings) as $name) {
            $this->get($name);
        }

        return $this->instances;
    }

    /**
     * Structured constructor-introspection data for an abstract; never echoes
     * or dies.
     *
     * @return array<string, mixed>
     */
    public function debugReflection(string $abstract): array
    {
        try {
            $reflector = new ReflectionClass($abstract);
            $constructor = $reflector->getConstructor();

            $info = [
                'class'      => $abstract,
                'file'       => $reflector->getFileName(),
                'parameters' => [],
            ];

            if (! $constructor) {
                return $info;
            }

            foreach ($constructor->getParameters() as $param) {
                $type = $param->getType();

                $paramInfo = [
                    'name'        => $param->getName(),
                    'type_class'  => $type ? get_class($type) : null,
                    'type_name'   => null,
                    'is_builtin'  => null,
                    'has_default' => $param->isDefaultValueAvailable(),
                ];

                if ($type instanceof ReflectionNamedType) {
                    $paramInfo['type_name'] = $type->getName();
                    $paramInfo['is_builtin'] = $type->isBuiltin();
                }

                $info['parameters'][] = $paramInfo;
            }

            return $info;
        } catch (\Exception $exception) {
            return ['error' => $exception->getMessage()];
        }
    }
}
