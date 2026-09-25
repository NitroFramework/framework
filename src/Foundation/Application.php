<?php

namespace Nitro\Foundation;

use Nitro\Container\Container;
use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Debug\Timeline;
use Nitro\Events\CoreEvents;
use Nitro\Events\EventServiceProvider;
use Nitro\Exceptions\ExceptionHandler;
use Nitro\Foundation\Bootstrap\BootstrapperInterface;
use Nitro\Foundation\Contracts\ApplicationInterface;
use Nitro\Foundation\Contracts\PathRegistry as PathRegistryContract;
use Nitro\Foundation\Events\ProviderEvent;
use Nitro\Foundation\Providers\ServiceProvider;
use Nitro\Http\Client\Factory as HttpClientFactory;
use Nitro\Http\Kernel;
use Nitro\Http\Request;
use Nitro\Support\Logger;
use Nitro\Thrust\Concerns\ResetsForWorkerMode;
use RuntimeException;
use Throwable;

/**
 * The application: owns the container and paths, bootstraps, and registers and boots the service providers.
 */
class Application implements ApplicationInterface
{
    use ResetsForWorkerMode;

    const VERSION = '2.0';

    /** Resolves every application path from the base path. */
    private PathRegistry $paths;

    /** Whether {@see bootstrap()} has run. */
    private bool $bootstrapped = false;

    /** The service container this application is assembled in. */
    private ContainerInterface $container;

    /** The configuration, set by the LoadConfiguration bootstrapper. */
    private ?Config $config = null;

    /**
     * Registered providers, in registration order.
     *
     * @var array<int, ServiceProvider>
     */
    private array $serviceProviders = [];

    /**
     * Registered providers, by class name.
     *
     * @var array<class-string, ServiceProvider>
     */
    private array $loadedProviders = [];

    /**
     * Deferred providers seen but not yet registered, by class name.
     *
     * @var array<class-string, ServiceProvider>
     */
    private array $deferredProviders = [];

    /**
     * Registered providers that have a boot() method, in registration order.
     *
     * @var array<int, ServiceProvider>
     */
    protected array $bootableProviders = [];

    /**
     * The provider of each deferred service, removed once that provider registers.
     *
     * @var array<string, class-string>
     */
    private array $deferredServices = [];

    /**
     * Bootstrapper classes, in the order {@see runBootstrappers()} runs them.
     *
     * @var array<int, class-string>
     */
    private array $bootstrappers = [];

    /**
     * Hooks run before a bootstrapper, keyed by its class.
     *
     * @var array<class-string, array<int, callable>>
     */
    private array $beforeBootstrappingHooks = [];

    /**
     * Hooks run after a bootstrapper, keyed by its class.
     *
     * @var array<class-string, array<int, callable>>
     */
    private array $afterBootstrappingHooks = [];

    /**
     * Hooks run just before providers boot.
     *
     * @var array<int, callable>
     */
    private array $bootingHooks = [];

    /**
     * Hooks run just after providers boot.
     *
     * @var array<int, callable>
     */
    private array $bootedHooks = [];

    /**
     * Callbacks to run once the response has been sent, cleared as they run.
     *
     * @var array<int, callable>
     */
    private array $terminatingCallbacks = [];

    /**
     * Assemble the application around a container, without bootstrapping it.
     *
     * @param string                  $basePath  Application root, which every other path derives from.
     * @param ContainerInterface|null $container Container to build in; the shared instance when omitted.
     */
    public function __construct(string $basePath, ?ContainerInterface $container = null)
    {
        $this->paths = new PathRegistry($basePath);

        if ($container) {
            $this->container = $container;
        } elseif (Container::hasInstance()) {
            $this->container = Container::getInstance();
        } else {
            $this->container = new Container();
            Container::setInstance($this->container);
        }

        $this->registerBaseBindings();
        $this->registerCoreBootstrappers();

        if (method_exists($this->container, 'setDeferredResolver')) {
            $this->container->setDeferredResolver(
                fn(string $abstract): bool => $this->loadDeferredProvider($abstract),
                fn(string $abstract): bool => $this->isDeferredService($abstract),
            );
        }
    }

    /**
     * Build the application for a front controller, with a fatal-error page for a failed boot.
     *
     * Construct it directly where a broken boot should throw instead, such as in a test.
     */
    public static function create(string $basePath): static
    {
        if (NITRO_PROFILE) {
            BootProfile::mark('autoload');
        }

        $app = new static($basePath);
        $app->registerFatalHandler();

        return $app;
    }

    /**
     * Bootstrap the application and hand the request to the HTTP kernel.
     */
    public function run(): void
    {
        if (!$this->bootstrapped) {
            $this->bootstrap();
        }

        $this->handle(Kernel::class);
    }

    /**
     * Resolve a kernel and run it once.
     *
     * @param class-string $kernelClass Kernel to resolve from the container.
     */
    public function handle(string $kernelClass): void
    {
        $kernel = $this->container->resolve($kernelClass);
        $kernel->run();
    }

    /**
     * Report a failure during bootstrap, before the real exception handler is installed.
     *
     * Renders a 500 page, or writes to standard error under the CLI, where the output may be a worker's response.
     */
    protected function registerFatalHandler(): void
    {
        set_exception_handler(static function (Throwable $exception): void {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            $detail = $exception->getMessage() . "\n" . $exception->getFile() . ':' . $exception->getLine()
                . "\n\n" . $exception->getTraceAsString();

            if (PHP_SAPI === 'cli') {
                fwrite(defined('STDERR') ? STDERR : fopen('php://stderr', 'w'), "FATAL: {$detail}\n");
                exit(1);
            }

            if (!headers_sent()) {
                http_response_code(500);
            }
            echo '<pre>' . htmlspecialchars($detail, ENT_QUOTES) . '</pre>';
            exit(1);
        });
    }

    /**
     * Run the booting hooks, the bootstrappers, each provider's boot() and the booted hooks.
     *
     * Runs once per process: once per request under php-fpm, once per worker under Thrust.
     *
     * @throws RuntimeException When the application is already bootstrapped.
     */
    public function bootstrap(): self
    {
        if ($this->bootstrapped) {
            throw new RuntimeException('Application already bootstrapped');
        }

        /** Emit point: app.bootstrapping, before anything is loaded. */
        $this->raise(CoreEvents::APP_BOOTSTRAPPING);

        if (NITRO_PROFILE && BootProfile::enabled()) {
            $this->container->observeResolutions(static function (): void {
                BootProfile::count('resolve');
            });
        }

        $this->runHooks($this->bootingHooks);

        if (NITRO_PROFILE) {
            BootProfile::mark('bootingHooks');
        }

        $this->runBootstrappers();

        $this->bootProviders();

        if (NITRO_PROFILE) {
            BootProfile::mark('bootProviders');
        }

        $this->runHooks($this->bootedHooks);

        if (NITRO_PROFILE) {
            BootProfile::mark('bootedHooks');
        }

        $this->bootstrapped = true;

        /** Emit point: app.bootstrapped, once every provider has registered and booted. */
        $this->raise(CoreEvents::APP_BOOTSTRAPPED);

        return $this;
    }

    /** Raise a lifecycle event, once the event bus exists and something listens. */
    private function raise(string $event, mixed $payload = []): void
    {
        if (!$this->container->has('events')) {
            return;
        }

        $dispatcher = $this->container->resolve('events');

        if ($dispatcher->hasListeners($event)) {
            $dispatcher->dispatch($event, $payload);
        }
    }

    /**
     * Bind what must exist before any bootstrapper or provider runs, reading no configuration.
     */
    protected function registerBaseBindings(): void
    {
        $this->registerSelf();
        $this->registerPaths();
        $this->registerBaseServiceProviders();
        $this->registerCoreServices();
    }

    /** Register the event bus first, since every later provider may use it. */
    private function registerBaseServiceProviders(): void
    {
        $this->register(new EventServiceProvider($this->container));
    }

    /** Bind the application and its container under their names and contracts. */
    private function registerSelf(): void
    {
        $this->container->instance('app', $this);
        $this->container->instance(Application::class, $this);
        $this->container->instance(ApplicationInterface::class, $this);
        $this->container->instance(ContainerInterface::class, $this->container);
        $this->container->instance(Container::class, $this->container);
    }

    /** Bind the path registry. */
    private function registerPaths(): void
    {
        $this->container->instance('paths', $this->paths);
        $this->container->instance(PathRegistry::class, $this->paths);
        $this->container->instance(PathRegistryContract::class, $this->paths);
    }

    /**
     * Bind the HTTP client, the facade aliases and the HTTP kernel, and set the default log file.
     *
     * The kernel is shared, so hooks providers attach at boot are on the instance that runs.
     */
    private function registerCoreServices(): void
    {
        $this->container->singleton('http.client', fn() => new HttpClientFactory());

        $this->registerFacadeBindings();

        $this->container->alias('http.client', HttpClientFactory::class);

        $this->container->singleton(Kernel::class);

        Logger::setPath($this->paths->storage('logs/nitro.log'));
    }

    /** Register the facade aliases and the maintenance-mode store. */
    private function registerFacadeBindings(): void
    {
        CoreAliases::register($this->container);

        $this->container->singleton(MaintenanceMode::class, fn() => new MaintenanceMode(
            $this->paths->storage('framework/down')
        ));
    }

    /** Queue the core bootstrappers. */
    protected function registerCoreBootstrappers(): void
    {
        $this->bootstrappers = [
            Bootstrap\LoadEnvironment::class,
            Bootstrap\LoadConfiguration::class,
            Bootstrap\HandleExceptions::class,
            Bootstrap\RegisterProviders::class,
        ];
    }

    /** Get the container the application is assembled in. */
    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * Get the bootstrapper classes, in the order they run.
     *
     * @return array<int, class-string>
     */
    public function getBootstrappers(): array
    {
        return $this->bootstrappers;
    }

    /**
     * Get the registered providers, in registration order.
     *
     * @return array<int, ServiceProvider>
     */
    public function getServiceProviders(): array
    {
        return $this->serviceProviders;
    }

    /**
     * Get the registered providers that have a boot() method, in boot order.
     *
     * @return array<int, ServiceProvider>
     */
    public function getBootableProviders(): array
    {
        return $this->bootableProviders;
    }

    /**
     * Get each deferred service's provider, for providers that have not registered yet.
     *
     * @return array<string, class-string>
     */
    public function getDeferredServices(): array
    {
        return $this->deferredServices;
    }

    /**
     * Get the registered providers, by class name.
     *
     * @return array<class-string, ServiceProvider>
     */
    public function getLoadedProviders(): array
    {
        return $this->loadedProviders;
    }

    /**
     * Get the registered instance of a provider, or null if it has not registered.
     *
     * @param class-string|ServiceProvider $provider
     */
    public function getProvider(string|ServiceProvider $provider): ?ServiceProvider
    {
        $class = is_string($provider) ? $provider : $provider::class;

        return $this->loadedProviders[$class] ?? null;
    }

    /**
     * Determine whether a provider has registered.
     *
     * A deferred provider has registered only once one of its services was resolved.
     *
     * @param class-string|ServiceProvider $provider
     */
    public function providerIsLoaded(string|ServiceProvider $provider): bool
    {
        return $this->getProvider($provider) !== null;
    }

    /**
     * Build a provider without registering it.
     *
     * @param class-string $provider
     */
    public function resolveProvider(string $provider): ServiceProvider
    {
        return new $provider($this->container);
    }

    /** Determine whether a service waits on a provider that has not registered yet. */
    public function isDeferredService(string $service): bool
    {
        return isset($this->deferredServices[$service]);
    }

    /** Determine whether the configuration has been compiled. */
    public function configurationIsCached(): bool
    {
        return is_file($this->paths()->cachedConfig());
    }

    /** Determine whether the route table has been compiled. */
    public function routesAreCached(): bool
    {
        return is_file($this->paths()->cachedRoutes());
    }

    /** Determine whether the listener map has been compiled. */
    public function eventsAreCached(): bool
    {
        return is_file($this->paths()->cachedEvents());
    }

    /** Determine whether `nitro optimize` has compiled the provider list. */
    public function providersAreCached(): bool
    {
        return is_file($this->paths()->cachedProviders());
    }

    /** Set the configuration, once the LoadConfiguration bootstrapper has loaded it. */
    public function setConfig(Config $config): void
    {
        $this->config = $config;
    }

    /** Run each bootstrapper with its before and after hooks. */
    protected function runBootstrappers(): void
    {
        foreach ($this->bootstrappers as $bootstrapper) {
            $this->runBootstrapperHooks($this->beforeBootstrappingHooks, $bootstrapper);

            $instance = $this->container->resolve($bootstrapper);

            if ($instance instanceof BootstrapperInterface) {
                $instance->bootstrap($this);
            }

            $this->runBootstrapperHooks($this->afterBootstrappingHooks, $bootstrapper);

            if (NITRO_PROFILE && BootProfile::enabled()) {
                $short = strrchr($bootstrapper, '\\');

                BootProfile::mark(lcfirst($short === false ? $bootstrapper : substr($short, 1)));
            }
        }
    }

    /**
     * Get every provider the application runs: the framework's, packages', the application's, then modules'.
     *
     * @return array<int, class-string>
     */
    public function configuredProviders(): array
    {
        return array_merge(
            $this->getDefaultProviders(),
            $this->discoverPackageProviders(),
            $this->config->get('app.providers'),
            $this->discoverModuleProviders()
        );
    }

    /**
     * Register the configured providers, or a precomputed list and deferred map from a cache.
     *
     * @param array<int, class-string>|null                $providers The providers to register, or null for all configured ones.
     * @param array<string, class-string>|null             $deferred  Each deferred service's provider.
     * @param array<class-string, array<int, string>>|null $when      The events that register each deferred provider.
     */
    public function registerConfiguredProviders(
        ?array $providers = null,
        ?array $deferred = null,
        ?array $when = null,
    ): void {
        $providers ??= $this->configuredProviders();

        if ($deferred !== null) {
            $this->deferredServices = $deferred + $this->deferredServices;
        }

        if ($when !== null && $when !== []) {
            $this->registerLoadEvents($when);
        }

        foreach ($providers as $providerClass) {
            $this->register($providerClass);
        }
    }

    /**
     * Register a deferred provider when one of its events is dispatched.
     *
     * @param array<class-string, array<int, string>> $when
     */
    private function registerLoadEvents(array $when): void
    {
        if (!$this->container->has('events')) {
            return;
        }

        $dispatcher = $this->container->resolve('events');

        foreach ($when as $providerClass => $events) {
            if ($events === []) {
                continue;
            }

            $dispatcher->listen($events, function () use ($providerClass): void {
                $this->registerDeferredProvider($providerClass);
            });
        }
    }

    /**
     * Discover the providers of modules under app/Modules.
     *
     * @return array<int, class-string>
     */
    private function discoverModuleProviders(): array
    {
        $modulesPath = $this->paths->base('app' . DIRECTORY_SEPARATOR . 'Modules');

        return (new ModuleManifest($modulesPath))->providers();
    }

    /**
     * Discover the providers installed packages declare under extra.nitro.providers.
     *
     * @return array<int, class-string>
     */
    private function discoverPackageProviders(): array
    {
        return (new PackageManifest(
            $this->paths->base('vendor'),
            $this->paths->base(),
            $this->paths->cachedPackages(),
        ))->providers();
    }

    /**
     * Get the framework's own service providers.
     *
     * @return array<int, class-string>
     */
    public function getDefaultProviders(): array
    {
        return (new DefaultProviders())->toArray();
    }

    /**
     * Register a service provider, or record a deferred one until its services are needed.
     *
     * Registering a provider twice returns the first instance.
     *
     * @param string|ServiceProvider $provider Provider class name, or an instance to adopt.
     * @return ServiceProvider The registered instance.
     */
    public function register(string|ServiceProvider $provider): ServiceProvider
    {
        $className = is_string($provider) ? $provider : get_class($provider);

        if (isset($this->loadedProviders[$className])) {
            return $this->loadedProviders[$className];
        }

        if (isset($this->deferredProviders[$className])) {
            return $this->deferredProviders[$className];
        }

        $instance = is_string($provider)
            ? new $provider($this->container)
            : $provider;

        if ($instance->isDeferred()) {
            foreach ($instance->provides() as $service) {
                $this->deferredServices[$service] = $className;
            }
            $this->deferredProviders[$className] = $instance;
            return $instance;
        }

        /** Emit point: provider.registering, before a provider's register() runs. */
        $this->raise(CoreEvents::PROVIDER_REGISTERING, new ProviderEvent($className));

        $instance->register();

        $this->serviceProviders[] = $instance;
        $this->loadedProviders[$className] = $instance;

        if (method_exists($instance, 'boot')) {
            $this->bootableProviders[] = $instance;
        }

        /** Emit point: provider.registered, after a provider's register() returns. */
        $this->raise(CoreEvents::PROVIDER_REGISTERED, new ProviderEvent($className));

        return $instance;
    }

    /**
     * Register the deferred provider of a service the container was asked for.
     *
     * @return bool Whether the service belonged to a deferred provider.
     */
    public function loadDeferredProvider(string $abstract): bool
    {
        if (!isset($this->deferredServices[$abstract])) {
            return false;
        }

        $this->registerDeferredProvider($this->deferredServices[$abstract]);

        return true;
    }

    /**
     * Register and boot a deferred provider.
     *
     * Its services leave the deferred map before register() runs, so a resolve from inside it cannot loop.
     */
    public function registerDeferredProvider(string $providerClass): void
    {
        $instance = $this->loadedProviders[$providerClass]
            ?? $this->deferredProviders[$providerClass]
            ?? new $providerClass($this->container);

        $waiting = false;

        foreach ($instance->provides() as $service) {
            if (isset($this->deferredServices[$service])) {
                $waiting = true;
                unset($this->deferredServices[$service]);
            }
        }

        if (!$waiting && in_array($instance, $this->serviceProviders, true)) {
            return;
        }

        $instance->register();
        $this->serviceProviders[] = $instance;
        $this->loadedProviders[$providerClass] = $instance;
        unset($this->deferredProviders[$providerClass]);

        if (method_exists($instance, 'boot')) {
            $this->container->call([$instance, 'boot']);
        }
    }

    /** Boot each registered provider that has a boot() method, injecting its parameters. */
    public function bootProviders(): void
    {
        foreach ($this->bootableProviders as $provider) {
            $name = $provider::class;

            /** Emit point: provider.booting, before a provider's boot() runs. */
            $this->raise(CoreEvents::PROVIDER_BOOTING, new ProviderEvent($name));

            if (NITRO_PROFILE) {
                Timeline::mark(
                    'boot ' . (strrchr($name, '\\') === false ? $name : substr(strrchr($name, '\\'), 1))
                );
            }

            $this->container->call([$provider, 'boot']);

            /** Emit point: provider.booted, after a provider's boot() returns. */
            $this->raise(CoreEvents::PROVIDER_BOOTED, new ProviderEvent($name));
        }
    }

    /**
     * Append bootstrappers, to run after the core ones.
     *
     * @param array<int, class-string> $bootstrappers
     */
    public function bootstrapWith(array $bootstrappers): void
    {
        $this->bootstrappers = array_merge($this->bootstrappers, $bootstrappers);
    }

    /**
     * Run a hook just before a bootstrapper, given the application and the bootstrapper's class.
     *
     * @param class-string $bootstrapper
     */
    public function beforeBootstrapping(string $bootstrapper, callable $hook): void
    {
        $this->beforeBootstrappingHooks[$bootstrapper][] = $hook;
    }

    /**
     * Run a hook just after a bootstrapper, given the application and the bootstrapper's class.
     *
     * @param class-string $bootstrapper
     */
    public function afterBootstrapping(string $bootstrapper, callable $hook): void
    {
        $this->afterBootstrappingHooks[$bootstrapper][] = $hook;
    }

    /** Run a hook once the environment file has been read, before configuration loads. */
    public function afterLoadingEnvironment(callable $hook): void
    {
        $this->afterBootstrapping(Bootstrap\LoadEnvironment::class, $hook);
    }

    /** Determine whether the bootstrappers have run. */
    public function hasBeenBootstrapped(): bool
    {
        return $this->bootstrapped;
    }

    /**
     * Run the hooks attached to one bootstrapper.
     *
     * @param array<class-string, array<int, callable>> $hooks
     * @param class-string $bootstrapper
     */
    private function runBootstrapperHooks(array $hooks, string $bootstrapper): void
    {
        foreach ($hooks[$bootstrapper] ?? [] as $hook) {
            $hook($this, $bootstrapper);
        }
    }

    /** Register a hook to run just before providers boot. */
    public function beforeBooting(callable $hook): void
    {
        $this->bootingHooks[] = $hook;
    }

    /**
     * Register a hook to run just after providers boot, or run it now if they already have.
     */
    public function booted(callable $hook): void
    {
        if ($this->bootstrapped) {
            $hook($this);
        } else {
            $this->bootedHooks[] = $hook;
        }
    }

    /**
     * Run a list of lifecycle hooks, passing the application to each.
     *
     * @param array<int, callable> $hooks
     */
    protected function runHooks(array $hooks): void
    {
        foreach ($hooks as $hook) {
            $hook($this);
        }
    }

    /** Get the path registry. */
    public function paths(): PathRegistryContract
    {
        return $this->paths;
    }

    /** Get the framework version. */
    public function version(): string
    {
        return self::VERSION;
    }

    /** Determine whether the application has been bootstrapped. */
    public function isBootstrapped(): bool
    {
        return $this->bootstrapped;
    }

    /** Determine whether debug mode is on, true before configuration loads. */
    public function isDebug(): bool
    {
        return $this->config?->get('app.debug', true) ?? true;
    }

    /**
     * Get the current environment, or whether it matches one of the given names.
     *
     * A name may use * as a wildcard: 'stag*' matches 'staging'.
     */
    public function environment(string ...$environments): string|bool
    {
        $current = $this->config?->get('app.env', 'production') ?? 'production';

        if ($environments === []) {
            return $current;
        }

        foreach ($environments as $pattern) {
            if ($pattern === $current) {
                return true;
            }

            if (
                str_contains($pattern, '*')
                && preg_match('#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#', $current) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    /** Determine whether the application runs in the local environment. */
    public function isLocal(): bool
    {
        return $this->environment('local') === true;
    }

    /** Determine whether the application runs in production. */
    public function isProduction(): bool
    {
        return $this->environment('production') === true;
    }

    /** Determine whether the application runs in the testing environment. */
    public function runningUnitTests(): bool
    {
        return $this->environment('testing') === true;
    }

    /** Determine whether the application is down for maintenance. */
    public function isDownForMaintenance(): bool
    {
        return $this->maintenanceMode()->active();
    }

    /** Get the maintenance-mode store, which an application may bind somewhere shared. */
    public function maintenanceMode(): MaintenanceMode
    {
        return $this->container->resolve(MaintenanceMode::class);
    }

    /** Get the locale in use. */
    public function getLocale(): string
    {
        return $this->translator()?->getLocale()
            ?? (string) ($this->config?->get('app.locale') ?? 'en');
    }

    /** Get the locale in use. */
    public function currentLocale(): string
    {
        return $this->getLocale();
    }

    /** Set the locale for the rest of this request. */
    public function setLocale(string $locale): void
    {
        $this->translator()?->setLocale($locale);

        $this->config?->set('app.locale', $locale);
    }

    /** Determine whether the given locale is the one in use. */
    public function isLocale(string $locale): bool
    {
        return $this->getLocale() === $locale;
    }

    /** Get the locale a missing translation falls back to. */
    public function getFallbackLocale(): string
    {
        return (string) ($this->config?->get('app.fallback_locale') ?? 'en');
    }

    /** Set the locale a missing translation falls back to. */
    public function setFallbackLocale(string $locale): void
    {
        $this->config?->set('app.fallback_locale', $locale);

        $translator = $this->translator();

        if ($translator !== null && method_exists($translator, 'setFallback')) {
            $translator->setFallback($locale);
        }
    }

    /** Get the translator, or null when none is bound. */
    private function translator(): ?object
    {
        return $this->container->has('translator')
            ? $this->container->resolve('translator')
            : null;
    }

    /** Register a callback to run after the response has been sent. */
    public function terminating(callable $callback): static
    {
        $this->terminatingCallbacks[] = $callback;

        return $this;
    }

    /**
     * Run the terminating callbacks, reporting a failure instead of letting it stop the rest.
     */
    public function terminate(): void
    {
        /** Emit point: app.terminating, after the response is sent and before the terminating callbacks. */
        $this->raise(CoreEvents::APP_TERMINATING);

        foreach ($this->terminatingCallbacks as $callback) {
            try {
                $this->container->call($callback);
            } catch (Throwable $exception) {
                $this->reportTerminationFailure($exception);
            }
        }

        $this->terminatingCallbacks = [];
    }

    /** Report a failure from a terminating callback, or drop it when nothing can report it. */
    private function reportTerminationFailure(Throwable $exception): void
    {
        try {
            $this->container->resolve(ExceptionHandler::class)->report($exception);
        } catch (Throwable) {
        }
    }

    /** Determine whether the application runs from the command line. */
    public function runningInConsole(): bool
    {
        return php_sapi_name() === 'cli' || php_sapi_name() === 'phpdbg';
    }
}
