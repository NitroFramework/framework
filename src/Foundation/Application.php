<?php

namespace Nitro\Foundation;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application as BaseApplication;
use Illuminate\Support\ServiceProvider;
use Nitro\Components\Registry;
use Nitro\Foundation\Configuration\ApplicationBuilder;

/**
 * The Nitro application.
 *
 * Core services are built on demand from the component registry instead of being registered
 * by service providers on every request, and autowiring runs through compiled factories
 * (bootstrap/cache/factories.php) instead of reflection. Component providers, providers whose
 * services are components (Registry::PROVIDERS), are skipped; package and app providers load
 * as usual.
 */
class Application extends BaseApplication
{
    protected static string $applicationBuilder = ApplicationBuilder::class;

    /** @var array<string, array{0: class-string, 1: string, 2: bool}> */
    protected array $components = Registry::MAP;

    protected ?object $compiledFactories = null;

    /** @var array<string, string> */
    protected array $compiledMap = [];

    /** @var array<string, array{register: float, boot: float}>|null */
    protected ?array $providerTimings = null;

    protected bool $ignoreCaches = false;

    public function __construct($basePath = null)
    {
        if ($basePath) {
            $this->setBasePath($basePath);
        }

        /**
         * Base bindings and core aliases. No base providers: events, log, context and routing
         * are components.
         */
        static::setInstance($this);
        $this->instances['app'] = $this;
        $this->instances[Container::class] = $this;

        /** The core alias list, computed once per process and copied per app. */
        [$aliases, $abstractAliases] = Registry::aliasTables(function () {
            $this->registerCoreContainerAliases();

            return [$this->aliases, $this->abstractAliases];
        });

        $this->aliases = $aliases + $this->aliases;
        $this->abstractAliases = $abstractAliases;

        if (! isset($this->aliases[static::class])) {
            $this->aliases[static::class] = 'app';
            $this->abstractAliases['app'][] = static::class;
        }

        $this->registerLaravelCloudServices();
    }

    protected function getConcrete($abstract)
    {
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]['concrete'];
        }

        if (isset($this->components[$abstract])) {
            [$class, $method] = $this->components[$abstract];

            return $class::$method(...);
        }

        /** Contextual bindings added after `artisan optimize` still win: fall back to reflection. */
        if (isset($this->compiledMap[$abstract]) && ! isset($this->contextual[$abstract])) {
            return $this->compiledFactories->{$this->compiledMap[$abstract]}(...);
        }

        return parent::getConcrete($abstract);
    }

    public function isShared($abstract)
    {
        return isset($this->components[$abstract]) && ! isset($this->bindings[$abstract])
            ? $this->components[$abstract][2]
            : parent::isShared($abstract);
    }

    public function bound($abstract)
    {
        return isset($this->components[$abstract]) || parent::bound($abstract);
    }

    public function getBindings()
    {
        $bindings = parent::getBindings();

        foreach ($this->components as $abstract => [$class, $method, $shared]) {
            $bindings[$abstract] ??= ['concrete' => $class::$method(...), 'shared' => $shared];
        }

        return $bindings;
    }

    /**
     * Register an additional component: a static factory called as fn($app, $params).
     */
    public function component(string $abstract, string $class, string $method, bool $shared = true): void
    {
        $this->components[$abstract] = [$class, $method, $shared];
    }

    public function hasComponent(string $abstract): bool
    {
        return isset($this->components[$abstract]);
    }

    /**
     * True when an app/package registered its own binding (overriding any component default).
     */
    public function hasExplicitBinding(string $abstract): bool
    {
        return isset($this->bindings[$abstract]);
    }

    public function setCompiledFactories(object $factories, array $map): void
    {
        $this->compiledFactories = $factories;
        $this->compiledMap = $map;
    }

    public function flush()
    {
        parent::flush();

        $this->components = Registry::MAP;
        $this->compiledFactories = null;
        $this->compiledMap = [];
    }

    public function registerConfiguredProviders()
    {
        $config = $this->make('config');

        /** Component providers are skipped: their services are components. */
        $config->set('app.providers', array_values(array_diff(
            (array) $config->get('app.providers', []),
            Registry::PROVIDERS
        )));

        parent::registerConfiguredProviders();
    }

    public function register($provider, $force = false)
    {
        /**
         * Registering a component provider explicitly (e.g. from a package) is a no-op: its
         * services already exist as components.
         */
        if (in_array(is_string($provider) ? $provider : get_class($provider), Registry::PROVIDERS, true)) {
            return is_string($provider) ? new $provider($this) : $provider;
        }

        if ($this->providerTimings === null) {
            return parent::register($provider, $force);
        }

        $started = hrtime(true);
        $registered = parent::register($provider, $force);
        $this->providerTimings[get_class($registered)]['register'] = (hrtime(true) - $started) / 1e6;
        $this->providerTimings[get_class($registered)]['boot'] ??= 0.0;

        return $registered;
    }

    protected function bootProvider(ServiceProvider $provider)
    {
        if ($this->providerTimings === null) {
            parent::bootProvider($provider);

            return;
        }

        $started = hrtime(true);
        parent::bootProvider($provider);
        $this->providerTimings[get_class($provider)]['boot'] = (hrtime(true) - $started) / 1e6;
        $this->providerTimings[get_class($provider)]['register'] ??= 0.0;
    }

    public function enableProviderProfiling(): void
    {
        $this->providerTimings = [];
    }

    /** @return array<string, array{register: float, boot: float}> Milliseconds per provider. */
    public function providerTimings(): array
    {
        return $this->providerTimings ?? [];
    }

    public function configurationIsCached()
    {
        return ! $this->ignoreCaches && parent::configurationIsCached();
    }

    public function routesAreCached()
    {
        return ! $this->ignoreCaches && parent::routesAreCached();
    }

    /**
     * Used by the optimizer, which compiles caches from a clean (uncached) boot.
     */
    public function ignoreCaches(bool $ignore = true): void
    {
        $this->ignoreCaches = $ignore;
    }

    public function getCachedFactoriesPath(): string
    {
        return $this->normalizeCachePath('APP_FACTORIES_CACHE', 'cache/factories.php');
    }

    /** Written by `artisan view:warm`: the compiled views, known to be current. */
    public function getCachedViewsManifestPath(): string
    {
        return $this->normalizeCachePath('APP_VIEWS_WARM_CACHE', 'cache/views-warm.php');
    }

    /**
     * Views are warm (`view:warm` ran) and the app is not in debug: the Blade compiler can skip
     * comparing each view's source mtime against its compiled file on every render.
     */
    public function viewsAreWarm(): bool
    {
        return ! $this->ignoreCaches
            && ! $this->make('config')->get('app.debug')
            && is_file($this->getCachedViewsManifestPath());
    }

    /**
     * Override console detection (e.g. to serve HTTP from a CLI-based process, or in tests).
     */
    public function setRunningInConsole(bool $console): void
    {
        $this->isRunningInConsole = $console;
    }
}
