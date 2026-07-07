<?php

namespace Nitro\Foundation;

use Nitro\Cache\CacheServiceProvider;
use Nitro\Container\Container;
use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Cookie\CookieServiceProvider;
use Nitro\Encryption\EncryptionServiceProvider;
use Nitro\Events\Dispatcher as EventDispatcher;
use Nitro\Filesystem\FilesystemServiceProvider;
use Nitro\Foundation\Bootstrap\BootstrapperInterface;
use Nitro\Foundation\Http\Kernel;
use Nitro\Foundation\Providers\AuthServiceProvider;
use Nitro\Foundation\Providers\ConsoleServiceProvider;
use Nitro\Foundation\Providers\DatabaseServiceProvider;
use Nitro\Foundation\Providers\ExceptionServiceProvider;
use Nitro\Foundation\Providers\HtmxServiceProvider;
use Nitro\Foundation\Providers\MailServiceProvider;
use Nitro\Foundation\Providers\RoutingServiceProvider;
use Nitro\Foundation\Providers\ServiceProvider;
use Nitro\Foundation\Providers\SessionServiceProvider;
use Nitro\Foundation\Providers\ValidationServiceProvider;
use Nitro\Foundation\Providers\ViewServiceProvider;
use Nitro\Notifications\NotificationServiceProvider;
use Nitro\PerformanceBar\PerformanceBarServiceProvider;
use Nitro\Queue\QueueServiceProvider;
use Nitro\Redis\RedisServiceProvider;
use Nitro\Scheduling\ScheduleServiceProvider;
use Nitro\Support\Logger;
use Nitro\Thrust\Concerns\ResetsForWorkerMode;
use RuntimeException;



/**
 * The application container and composition root.
 *
 * Owns the service container and the path registry, runs the bootstrap sequence
 * (environment, configuration, exception handling, provider registration), registers
 * and boots service providers (including discovered modules), and hands the request
 * off to the HTTP kernel. This is the object the whole framework is assembled around.
 */
class Application
{

    use ResetsForWorkerMode;


    const VERSION = '2.0';



    // Path registry instance for managing application paths
    private PathRegistry $paths;

    // Indicates if the application has been bootstrapped
    private bool $bootstrapped = false;

    // Service container instance
    private ContainerInterface $container;

    // Application configuration, injected by the LoadConfiguration bootstrapper
    // once config is loaded. Held as a typed dependency rather than pulled from
    // the container by string, so the Application never service-locates.
    private ?Config $config = null;

    // Registered service providers
    private array $serviceProviders = [];

    // Track loaded providers to prevent duplicates
    private array $loadedProviders = [];

    /**
     * Map of [serviceAbstract => providerClass] populated by deferred providers.
     * When the container is asked for a service in this map and doesn't already
     * have a binding, we register the provider on demand and remove the entry.
     */
    private array $deferredServices = [];



    // Bootstrappers to run during bootstrap
    private array $bootstrappers = [];

    // Hooks fired just before providers boot (during Application::bootstrap()).
    private array $bootingHooks = [];

    // Hooks fired just after providers boot.
    private array $bootedHooks = [];


    public function __construct(string $basePath, ?ContainerInterface $container = null)
    {
        $this->paths = new PathRegistry($basePath);

        if ($container) {
            $this->container = $container;
        } else {
            $this->container = Container::getInstance();
        }

        $this->registerBaseBindings();
        $this->registerCoreBootstrappers();

        // Wire deferred-provider resolution into the container so unresolved
        // bindings can trigger lazy registration on first use.
        if (method_exists($this->container, 'setDeferredResolver')) {
            $this->container->setDeferredResolver(
                fn(string $abstract): bool => $this->loadDeferredProvider($abstract)
            );
        }
    }

    public static function create(string $basePath): static
    {
        return new static($basePath);
    }

    public function handle(string $kernelClass): void
    {
        $kernel = $this->container->make($kernelClass);
        $kernel->run();
    }





    public function bootstrap(): self
    {
        if ($this->bootstrapped) {
            throw new RuntimeException('Application already bootstrapped');
        }

        $this->runHooks($this->bootingHooks);
        $this->runBootstrappers();
        $this->bootProviders();
        $this->runHooks($this->bootedHooks);

        $this->bootstrapped = true;
        return $this;
    }

    /** Register core container bindings (e.g. paths) */
    protected function registerBaseBindings(): void
    {
        $this->registerSelf();
        $this->registerPaths();
        $this->registerCoreServices();
    }

    private function registerSelf(): void
    {
        $this->container->instance('app', $this);
        $this->container->instance(Application::class, $this);
        $this->container->instance(ContainerInterface::class, $this->container);
        $this->container->instance(Container::class, $this->container);
    }

    private function registerPaths(): void
    {
        $this->container->instance('paths', $this->paths);
        $this->container->instance(PathRegistry::class, $this->paths);
    }

    private function registerCoreServices(): void
    {
        $this->container->singleton('events', EventDispatcher::class);

        // The HTTP kernel is a singleton so lifecycle hooks (requestReceived,
        // responseReady, terminating) registered during provider boot are
        // attached to the very instance that Application::handle() runs.
        $this->container->singleton(Kernel::class, Kernel::class);

        // Request is bound here as an alias placeholder; the actual instance is
        // attached by the Kernel via container->instance() once $_SERVER is
        // available. Binding a capture closure here AND re-capturing in the
        // Kernel produced two Request objects per request, only one of which
        // ever got used.

        Logger::setPath($this->paths->storage('logs/nitro.log'));
    }

    /** Register core bootstrappers to run during bootstrap */
    protected function registerCoreBootstrappers(): void
    {
        $this->bootstrappers = [
            Bootstrap\LoadEnvironment::class,
            Bootstrap\LoadConfiguration::class,
            Bootstrap\HandleExceptions::class,
            Bootstrap\RegisterProviders::class,
        ];
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * Inject the loaded configuration repository.
     *
     * Called by the LoadConfiguration bootstrapper once config is available.
     * Lets the Application read its own config (providers, env, debug) through a
     * typed dependency instead of resolving 'config' from the container.
     */
    public function setConfig(Config $config): void
    {
        $this->config = $config;
    }

    /** Run all bootstrappers in sequence */
    protected function runBootstrappers(): void
    {
        foreach ($this->bootstrappers as $bootstrapper) {
            $instance = $this->container->make($bootstrapper);

            if ($instance instanceof BootstrapperInterface) {
                $instance->bootstrap($this);
            }
        }
    }

    /**
     * Register service providers defined in configuration.
     *
     * Hot path: in production we hydrate a pre-merged list from bootstrap.php
     * (built by `nitro optimize`), avoiding both the array_merge and the
     * config lookup on every request. Falls back to live merging in dev.
     */
    public function registerConfiguredProviders(?array $providers = null): void
    {
        if ($providers === null) {
            $providers = array_merge(
                $this->getDefaultProviders(),
                $this->config->get('app.providers'),
                $this->discoverModuleProviders()
            );
        }

        foreach ($providers as $providerClass) {
            $this->register($providerClass);
        }
    }

    /**
     * Discover module service providers under app/Modules by scanning the
     * filesystem. Used only on the live (non-cached) path — in production the
     * providers are baked into bootstrap.php by `nitro optimize`, so this scan
     * never runs per request.
     *
     * @return array<int, class-string>
     */
    private function discoverModuleProviders(): array
    {
        $modulesPath = $this->paths->base('app' . DIRECTORY_SEPARATOR . 'Modules');

        return (new ModuleManifest($modulesPath))->providers();
    }

    /** Get the default service providers if none are defined in configuration */
    public function getDefaultProviders(): array
    {
        return [
            RoutingServiceProvider::class,
            SessionServiceProvider::class,
            ViewServiceProvider::class,
            DatabaseServiceProvider::class,
            ExceptionServiceProvider::class,
            EncryptionServiceProvider::class,
            CookieServiceProvider::class,
            ValidationServiceProvider::class,
            MailServiceProvider::class,
            NotificationServiceProvider::class,
            AuthServiceProvider::class,
            ConsoleServiceProvider::class,
            HtmxServiceProvider::class,
            PerformanceBarServiceProvider::class,
            CacheServiceProvider::class,
            FilesystemServiceProvider::class,
            RedisServiceProvider::class,
            QueueServiceProvider::class,
            ScheduleServiceProvider::class,
        ];
    }

    /**
     * Providers with a boot() method, in registration order.
     * Populated by register() so bootProviders() doesn't have to call
     * method_exists per provider per request.
     */
    protected array $bootableProviders = [];

    /** Register a service provider with the application */
    public function register(string|ServiceProvider $provider): ServiceProvider
    {
        $className = is_string($provider) ? $provider : get_class($provider);

        if (isset($this->loadedProviders[$className])) {
            return $this->loadedProviders[$className];
        }

        $instance = is_string($provider)
            ? new $provider($this->container)
            : $provider;

        // Deferred providers: record what they provide and skip register()
        // entirely until one of those services is actually resolved. The
        // provider isn't bootable until that point either — boot() runs after
        // its on-demand register().
        if ($instance->isDeferred()) {
            foreach ($instance->provides() as $service) {
                $this->deferredServices[$service] = $className;
            }
            $this->loadedProviders[$className] = $instance;
            return $instance;
        }

        $instance->register();

        $this->serviceProviders[] = $instance;
        $this->loadedProviders[$className] = $instance;

        if (method_exists($instance, 'boot')) {
            $this->bootableProviders[] = $instance;
        }

        return $instance;
    }

    /**
     * Called by the container when an unresolved abstract is requested.
     * Returns true if this resolves to a deferred provider and we successfully
     * registered (and booted, if applicable) it.
     */
    public function loadDeferredProvider(string $abstract): bool
    {
        if (!isset($this->deferredServices[$abstract])) {
            return false;
        }

        $providerClass = $this->deferredServices[$abstract];
        $instance = $this->loadedProviders[$providerClass] ?? new $providerClass($this->container);

        // Clear ALL services this provider offers before register() runs so a
        // re-entrant resolve from inside register() doesn't loop.
        foreach ($instance->provides() as $svc) {
            unset($this->deferredServices[$svc]);
        }

        $instance->register();
        $this->serviceProviders[] = $instance;
        $this->loadedProviders[$providerClass] = $instance;

        if (method_exists($instance, 'boot')) {
            $this->container->call([$instance, 'boot']);
        }

        return true;
    }

    /** Boot all registered service providers */
    public function bootProviders(): void
    {
        foreach ($this->bootableProviders as $provider) {
            // boot() existence was verified at register time; just inject deps.
            $this->container->call([$provider, 'boot']);
        }
    }

    // ============================================
    // LIFECYCLE HOOKS (PUBLIC API)
    // ============================================

    /** Allow external code to add bootstrappers to the bootstrap process */
    public function bootstrapWith(array $bootstrappers): void
    {
        $this->bootstrappers = array_merge($this->bootstrappers, $bootstrappers);
    }

    /** Register a hook to run just before providers boot. */
    public function beforeBooting(callable $hook): void
    {
        $this->bootingHooks[] = $hook;
    }

    /** Register a hook to run just after providers boot. */
    public function booted(callable $hook): void
    {
        if ($this->bootstrapped) {
            $hook($this);
        } else {
            $this->bootedHooks[] = $hook;
        }
    }

    // ============================================
    // LIFECYCLE HOOKS (Internal Firing)
    // ============================================

    /** Run a list of lifecycle hooks, passing the application to each. */
    protected function runHooks(array $hooks): void
    {
        foreach ($hooks as $hook) {
            $hook($this);
        }
    }


    // ============================================
    // UTILITIES
    // ============================================

    /** Get the path registry instance */
    public function paths(): PathRegistry
    {
        return $this->paths;
    }

    /** Get the application version */
    public function version(): string
    {
        return self::VERSION;
    }

    /** Check if the application has been bootstrapped */
    public function isBootstrapped(): bool
    {
        return $this->bootstrapped;
    }

    /** Check if the application is in debug mode (defaults to true if config not yet loaded) */
    public function isDebug(): bool
    {
        return $this->config?->get('app.debug', true) ?? true;
    }

    /** Get the current application environment, e.g. production, development (defaults to production pre-config) */
    public function environment(): string
    {
        return $this->config?->get('app.env', 'production') ?? 'production';
    }

    /** Check if the application is running in the console (CLI) */
    public function runningInConsole(): bool
    {
        return php_sapi_name() === 'cli' || php_sapi_name() === 'phpdbg';
    }
}
