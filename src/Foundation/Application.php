<?php

namespace Nitro\Foundation;

use Nitro\Auth\Access\Gate;
use Nitro\Auth\Passwords\PasswordBroker;
use Nitro\Broadcasting\BroadcastManager;
use Nitro\Broadcasting\BroadcastServiceProvider;
use Nitro\Cache\CacheServiceProvider;
use Nitro\Cache\RateLimiter;
use Nitro\Concurrency\ConcurrencyServiceProvider;
use Nitro\Console\Kernel as ConsoleKernel;
use Nitro\Container\Container;
use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Context\ContextServiceProvider;
use Nitro\Context\Repository as ContextRepository;
use Nitro\Cookie\CookieServiceProvider;
use Nitro\Encryption\EncryptionServiceProvider;
use Nitro\Events\Contracts\Dispatcher as EventDispatcher;
use Nitro\Events\CoreEvents;
use Nitro\Events\Dispatcher as BundledEventDispatcher;
use Nitro\Filesystem\FilesystemServiceProvider;
use Nitro\Foundation\Bootstrap\BootstrapperInterface;
use Nitro\Foundation\Contracts\ApplicationInterface;
use Nitro\Foundation\Contracts\PathRegistry as PathRegistryContract;
use Nitro\Foundation\Events\ProviderEvent;
use Nitro\Foundation\Providers\AuthServiceProvider;
use Nitro\Foundation\Providers\ConsoleServiceProvider;
use Nitro\Foundation\Providers\DatabaseServiceProvider;
use Nitro\Foundation\Providers\ExceptionServiceProvider;
use Nitro\Foundation\Providers\MailServiceProvider;
use Nitro\Foundation\Providers\RoutingServiceProvider;
use Nitro\Foundation\Providers\ServiceProvider;
use Nitro\Log\LogServiceProvider;
use Nitro\Session\SessionServiceProvider;
use Nitro\Foundation\Providers\ValidationServiceProvider;
use Nitro\Foundation\Providers\ViewServiceProvider;
use Nitro\Http\Client\Factory as HttpClientFactory;
use Nitro\Http\HttpServiceProvider;
use Nitro\Http\Kernel;
use Nitro\Http\Redirector;
use Nitro\Http\Request;
use Nitro\Http\ResponseFactory;
use Nitro\Notifications\NotificationServiceProvider;
use Nitro\Process\Factory as ProcessFactory;
use Nitro\Process\ProcessServiceProvider;
use Nitro\Queue\QueueServiceProvider;
use Nitro\Redis\RedisServiceProvider;
use Nitro\Scheduling\ScheduleServiceProvider;
use Nitro\Support\DateFactory;
use Nitro\Support\Hash;
use Nitro\Support\Logger;
use Nitro\Support\Pipeline;
use Nitro\Support\SupportServiceProvider;
use Nitro\Testing\ParallelTesting;
use Nitro\Inertia\InertiaServiceProvider;
use Nitro\Testing\TestingServiceProvider;
use Nitro\Thrust\Concerns\ResetsForWorkerMode;
use Nitro\Translation\TranslationServiceProvider;
use Nitro\Translation\Translator;
use Nitro\View\Blade;
use RuntimeException;



/**
 * The application container and composition root.
 *
 * Owns the service container and the path registry, runs the bootstrap sequence
 * (environment, configuration, exception handling, provider registration), registers
 * and boots service providers (including discovered modules), and hands the request
 * off to the HTTP kernel. This is the object the whole framework is assembled around.
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

    /**
     * Application configuration, injected by the LoadConfiguration bootstrapper
     * once config is loaded. Held as a typed dependency rather than pulled from
     * the container by string, so the Application never service-locates.
     */
    private ?Config $config = null;

    /**
     * Registered (non-deferred) providers, in registration order.
     *
     * @var array<int, ServiceProvider>
     */
    private array $serviceProviders = [];

    /**
     * Every provider instance seen, by class name, so registering twice is a
     * no-op rather than a second set of bindings.
     *
     * @var array<class-string, ServiceProvider>
     */
    private array $loadedProviders = [];

    /**
     * Providers that expose a boot(), in registration order.
     *
     * Populated by {@see register()} so {@see bootProviders()} doesn't have to
     * call method_exists per provider per request.
     *
     * @var array<int, ServiceProvider>
     */
    protected array $bootableProviders = [];

    /**
     * Map of [serviceAbstract => providerClass] populated by deferred providers.
     * When the container is asked for a service in this map and doesn't already
     * have a binding, we register the provider on demand and remove the entry.
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
     * Hooks fired just before providers boot, during {@see bootstrap()}.
     *
     * @var array<int, callable>
     */
    private array $bootingHooks = [];

    /**
     * Hooks fired just after providers boot.
     *
     * @var array<int, callable>
     */
    private array $bootedHooks = [];

    /**
     * Callbacks to run once the response has been sent.
     *
     * Cleared as they run, so a worker that handles the next request does not
     * fire the previous request's callbacks again.
     *
     * @var array<int, callable>
     */
    private array $terminatingCallbacks = [];

    /**
     * Assemble the application around a container, without bootstrapping it.
     *
     * Binds the base services and queues the core bootstrappers; nothing is
     * read from disk and no provider runs until {@see bootstrap()}.
     *
     * @param string                  $basePath  Application root, which every other path derives from.
     * @param ContainerInterface|null $container Container to build in; the shared instance when omitted.
     */
    public function __construct(string $basePath, ?ContainerInterface $container = null)
    {
        $this->paths = new PathRegistry($basePath);

        /* Adopt an established container, or create and publish the first. */
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

        /*
         * Wire deferred-provider resolution into the container so an unresolved
         * binding can trigger lazy registration on first use.
         */
        if (method_exists($this->container, 'setDeferredResolver')) {
            $this->container->setDeferredResolver(
                fn(string $abstract): bool => $this->loadDeferredProvider($abstract)
            );
        }
    }

    /**
     * Build an application with the bootstrap-time fatal handler installed.
     *
     * The entry-point constructor: what public/index.php calls. Use the
     * constructor directly where a broken boot should throw rather than render
     * — a test harness, or a console entry point that owns its own reporting.
     *
     * @see registerFatalHandler()
     */
    public static function create(string $basePath): static
    {
        /*
         * The first stage is everything PHP did before the framework existed —
         * composer's autoloader, and compiling whatever opcache did not already
         * hold. On php-fpm that is paid per request and is usually the largest
         * single figure, so it is separated from the bootstrappers rather than
         * folded into the first of them.
         */
        BootProfile::mark('autoload');

        $app = new static($basePath);
        $app->registerFatalHandler();

        return $app;
    }

    /**
     * Bootstrap the application, then hand the request to the HTTP kernel. The
     * whole front controller is:
     *
     *   Application::create(dirname(__DIR__))->run();
     *
     * Note what this does NOT contain: the request lifecycle. run() is two
     * steps — {@see bootstrap()} (phase 1, once per process) and then a single
     * hand-off to {@see \Nitro\Http\Kernel::run()} (phase 2, once
     * per request). Trace a request there, not here.
     *
     * Thrust does not call this at all: public/worker.php runs bootstrap() once
     * and then loops the kernel per request.
     */
    public function run(): void
    {
        if (! $this->bootstrapped) {
            $this->bootstrap();
        }

        $this->handle(Kernel::class);
    }

    /**
     * Resolve a kernel and run it once.
     *
     * The seam between the composition root and a delivery mechanism: the HTTP
     * kernel for a web request, the console kernel for a command. The kernel
     * owns the lifecycle from here on.
     *
     * @param class-string $kernelClass Kernel to resolve from the container.
     */
    public function handle(string $kernelClass): void
    {
        $kernel = $this->container->resolve($kernelClass);
        $kernel->run();
    }

    /**
     * A last-resort handler for fatal errors that occur DURING bootstrap, before
     * the HandleExceptions bootstrapper installs the real one (which supersedes
     * this). Not the app-facing error handler — just a floor so a broken boot
     * still returns a 500 instead of a blank page.
     *
     * Under the CLI — which includes a FrankenPHP worker — the failure goes to
     * stderr instead. A worker's stdout is the response body, so emitting HTML
     * there would corrupt whatever the process was serving.
     */
    protected function registerFatalHandler(): void
    {
        set_exception_handler(static function (\Throwable $exception): void {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            $detail = $exception->getMessage() . "\n" . $exception->getFile() . ':' . $exception->getLine()
                . "\n\n" . $exception->getTraceAsString();

            if (PHP_SAPI === 'cli') {
                fwrite(defined('STDERR') ? STDERR : fopen('php://stderr', 'w'), "FATAL: {$detail}\n");
                exit(1);
            }

            if (! headers_sent()) {
                http_response_code(500);
            }
            echo '<pre>' . htmlspecialchars($detail, ENT_QUOTES) . '</pre>';
            exit(1);
        });
    }

    /**
     * PHASE 1 of the lifecycle — boot. Runs ONCE per process.
     *
     * Under a classic SAPI (FPM/apache) that means once per request. Under
     * Thrust/FrankenPHP it means once per *worker*: this runs at worker start
     * and the warm Application is then reused for thousands of requests, so
     * anything cached here lives for the worker's whole life. That distinction
     * is the single most important fact about how Nitro executes.
     *
     * The sequence, in order:
     *
     *   1. bootingHooks       — beforeBooting() callbacks
     *   2. runBootstrappers() — LoadEnvironment, LoadConfiguration,
     *                           HandleExceptions, RegisterProviders (in that
     *                           order; the last one calls register() on every
     *                           provider from getDefaultProviders() + config)
     *   3. bootProviders()    — boot() on each provider that has one, in
     *                           registration order. This is where features
     *                           attach middleware, macros, and kernel hooks.
     *   4. bootedHooks        — booted() callbacks
     *
     * PHASE 2 — the per-request lifecycle — is NOT here. This class is the
     * composition root: it assembles the object graph and stops. Request
     * handling lives in {@see \Nitro\Http\Kernel::run()}
     * (capture → handle → send → terminate).
     *
     * To see what any of the above actually attached at runtime, rather than
     * grepping for it: `php nitro lifecycle`.
     */
    public function bootstrap(): self
    {
        if ($this->bootstrapped) {
            throw new RuntimeException('Application already bootstrapped');
        }

        /**
         * Emit point — app.bootstrapping
         *
         * The earliest event there is. Almost nothing is bound yet and the
         * dispatcher itself may not exist, so this is usually heard only by a
         * listener attached before bootstrap() was called — a profiler
         * wrapping the whole boot, for instance. Payload: none.
         */
        $this->raise(CoreEvents::APP_BOOTSTRAPPING);

        /*
         * Counting resolutions costs one call per resolve and answers the
         * question a duration cannot: whether the container is being asked
         * for too much, or simply asked slowly.
         */
        if (BootProfile::enabled()) {
            $this->container->observeResolutions(static function (): void {
                BootProfile::count('resolve');
            });
        }

        $this->runHooks($this->bootingHooks);
        BootProfile::mark('bootingHooks');

        $this->runBootstrappers();

        /*
         * Provider boot() is its own stage. It runs after the bootstrappers,
         * so without a mark here its cost is charged to whatever is measured
         * next — which made route matching look far dearer than it is.
         */
        $this->bootProviders();
        BootProfile::mark('bootProviders');

        $this->runHooks($this->bootedHooks);
        BootProfile::mark('bootedHooks');

        $this->bootstrapped = true;

        /**
         * Emit point — app.bootstrapped
         *
         * Every provider has registered and booted and the container is fully
         * populated. The first point at which a listener registered by an
         * application's own provider can be sure everything it needs exists.
         * Payload: none.
         */
        $this->raise(CoreEvents::APP_BOOTSTRAPPED);

        return $this;
    }

    /**
     * Raise a framework lifecycle event, if anything can hear it yet.
     *
     * The application is where the dispatcher comes from, so it cannot be
     * handed one the way other layers are — it asks its own container. Early
     * bootstrap runs before that container has an 'events' binding at all, and
     * a listener registered by a provider cannot hear an event raised before
     * that provider ran. Both cases are silence by design, and neither is ever
     * the reason a boot fails.
     */
    private function raise(string $event, mixed $payload = []): void
    {
        if (! $this->container->has('events')) {
            return;
        }

        $dispatcher = $this->container->resolve('events');

        if ($dispatcher->hasListeners($event)) {
            $dispatcher->dispatch($event, $payload);
        }
    }

    /**
     * Bind everything that must exist before any bootstrapper or provider runs.
     *
     * Runs from the constructor, so nothing here may read configuration — it is
     * not loaded yet.
     */
    protected function registerBaseBindings(): void
    {
        $this->registerSelf();
        $this->registerPaths();
        $this->registerCoreServices();
    }

    /** Make the application and its container resolvable from within itself. */
    private function registerSelf(): void
    {
        $this->container->instance('app', $this);
        $this->container->instance(Application::class, $this);

        // So a provider or middleware can depend on the contract rather than on
        // the composition root, the way it already can for config and container.
        $this->container->instance(ApplicationInterface::class, $this);
        $this->container->instance(ContainerInterface::class, $this->container);
        $this->container->instance(Container::class, $this->container);
    }

    /** Share the one path registry every path helper resolves through. */
    private function registerPaths(): void
    {
        $this->container->instance('paths', $this->paths);
        $this->container->instance(PathRegistry::class, $this->paths);
        $this->container->instance(PathRegistryContract::class, $this->paths);
    }

    /**
     * Bind the services that exist independently of any provider: the event
     * dispatcher, the HTTP client, the kernel, and the request's lifetime.
     */
    private function registerCoreServices(): void
    {
        /*
         * Built via a closure rather than by class name so the dispatcher gets
         * the container: it needs one to resolve a listener named by class, and
         * to reach the queue for a listener that should not run in the request.
         */
        $this->container->singleton('events', function ($container) {
            $dispatcher = new BundledEventDispatcher();
            $dispatcher->setContainer($container);

            /*
             * How an after-commit event finds out whether there is a commit to
             * wait for. Null when nothing has started a transaction, which is
             * also the answer in an application with no database — and in both
             * cases dispatching immediately is correct.
             */
            $dispatcher->setTransactionManagerResolver(
                static fn (): ?object => \Nitro\Database\DB::transactions()
            );

            return $dispatcher;
        });

        /*
         * The contract is the name the framework resolves by; the concrete is
         * aliased too so an application that named it keeps working. Binding
         * something else to the contract replaces the bus everywhere.
         */
        $this->container->alias('events', EventDispatcher::class);
        $this->container->alias('events', BundledEventDispatcher::class);

        /*
         * A singleton so that a fake installed in a test is the same instance
         * the code under test sends through. Each verb still builds its own
         * PendingRequest, so nothing configured on one request leaks.
         */
        $this->container->singleton('http.client', fn () => new HttpClientFactory());

        $this->registerFacadeBindings();

        $this->container->alias('http.client', HttpClientFactory::class);

        /*
         * The HTTP kernel is a singleton so lifecycle hooks (requestReceived,
         * responseReady, terminating) registered during provider boot are
         * attached to the very instance that Application::handle() runs.
         */
        $this->container->singleton(Kernel::class);

        /*
         * Nothing binds the request here: the Kernel attaches the instance once
         * $_SERVER is available. Binding a capture closure here AND re-capturing
         * in the Kernel produced two Request objects per request, only one of
         * which was ever used.
         */

        Logger::setPath($this->paths->storage('logs/nitro.log'));
    }

    /**
     * Bind the services the facades resolve through.
     *
     * A facade is only a front door: it needs the binding behind it to exist,
     * or the first call is a resolution error rather than a missing method.
     *
     * Names only. The composition root says that `hash` and Support\Hash are the
     * same service; it does not say how to build one — that is the owning
     * provider's job, and this method used to do both. Binding here meant the
     * Application carried construction knowledge for a dozen subsystems, bound
     * every one of them on every request, and drifted: `blade`, `artisan`,
     * `rate.limiter` and `auth.password` were each bound here AND in their own
     * provider, and in the last two the provider passed real dependencies while
     * this autowired. The provider registers later and wins, so the copies here
     * were dead — and would have been wrong if they hadn't been.
     */
    private function registerFacadeBindings(): void
    {
        $aliases = [
            'blade'            => Blade::class,
            'gate'             => Gate::class,
            'hash'             => Hash::class,
            'date'             => DateFactory::class,
            'response'         => ResponseFactory::class,
            'redirect'         => Redirector::class,
            'rate.limiter'     => RateLimiter::class,
            'auth.password'    => PasswordBroker::class,
            'artisan'          => ConsoleKernel::class,
            'context'          => ContextRepository::class,
            'process'          => ProcessFactory::class,
            'parallel.testing' => ParallelTesting::class,
            'translator'       => Translator::class,
            'broadcast'        => BroadcastManager::class,
            'pipeline'         => Pipeline::class,
            'maintenance'      => MaintenanceMode::class,
        ];

        foreach ($aliases as $name => $class) {
            /*
             * The class is the binding and the short name points at it, never
             * the reverse. Binding the short name and then aliasing the class
             * back to it closes a loop: resolving either one asks for the
             * other for as long as the process has memory.
             */
            $this->container->alias($class, $name);
        }

        /*
         * The one binding left here, and Foundation's own: the down file's
         * location comes from the path registry, which no provider has a better
         * claim to than the object that built it. Eager rather than deferred
         * because the maintenance guard asks for it on every request, so
         * deferring would only move the load, not remove it.
         */
        $this->container->singleton(MaintenanceMode::class, fn () => new MaintenanceMode(
            $this->paths->storage('framework/down')
        ));
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
     * The bootstrapper classes, in the order runBootstrappers() will run them.
     *
     * @return array<int, class-string>
     */
    public function getBootstrappers(): array
    {
        return $this->bootstrappers;
    }

    /**
     * Every registered (non-deferred) provider instance, in registration order.
     *
     * @return array<int, ServiceProvider>
     */
    public function getServiceProviders(): array
    {
        return $this->serviceProviders;
    }

    /**
     * Providers that expose a boot(), in the order bootProviders() calls them.
     *
     * @return array<int, ServiceProvider>
     */
    public function getBootableProviders(): array
    {
        return $this->bootableProviders;
    }

    /**
     * Deferred services: [serviceAbstract => providerClass]. These providers
     * have NOT registered or booted; the container loads them on first resolve.
     *
     * @return array<string, class-string>
     */
    public function getDeferredServices(): array
    {
        return $this->deferredServices;
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
            $instance = $this->container->resolve($bootstrapper);

            if ($instance instanceof BootstrapperInterface) {
                $instance->bootstrap($this);
            }

            /*
             * Marked here rather than inside each bootstrapper so the stage
             * names cannot drift from the list that produced them, and a
             * bootstrapper an application adds is timed without knowing it.
             * Guarded rather than passed straight in, so naming the stage
             * costs nothing when nobody is profiling.
             */
            if (BootProfile::enabled()) {
                $short = strrchr($bootstrapper, '\\');

                BootProfile::mark(lcfirst($short === false ? $bootstrapper : substr($short, 1)));
            }
        }
    }

    /**
     * Register service providers defined in configuration.
     *
     * Hot path: in production we hydrate a pre-merged list from bootstrap.php
     * (built by `nitro optimize`), avoiding both the array_merge and the
     * config lookup on every request. Falls back to live merging in dev.
     *
     * @param array<int, class-string>|null   $providers Pre-merged eager list, or null to discover live.
     * @param array<string, class-string>|null $deferred [service => providerClass] from the cache.
     */
    public function registerConfiguredProviders(?array $providers = null, ?array $deferred = null): void
    {
        if ($providers === null) {
            $providers = array_merge(
                $this->getDefaultProviders(),
                $this->discoverPackageProviders(),
                $this->config->get('app.providers'),
                $this->discoverModuleProviders()
            );
        }

        /*
         * Seeded straight from the cache, so a deferred provider's class is never
         * loaded to be asked whether it defers. register() has to construct one
         * before it can call isDeferred(), which meant a deferred provider still
         * cost its class on every request — the one thing deferring was for.
         * `nitro optimize` answers that question once and writes the map.
         */
        if ($deferred !== null) {
            $this->deferredServices = $deferred + $this->deferredServices;
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

    /**
     * Discover service providers from installed Composer packages that opt in via
     * `extra.nitro.providers` (Laravel-style package auto-discovery). Live path
     * only — in production `nitro optimize` bakes them into the bootstrap cache,
     * so installed.json is not read per request.
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

    /** Get the default service providers if none are defined in configuration */
    public function getDefaultProviders(): array
    {
        return [
            LogServiceProvider::class,
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
            CacheServiceProvider::class,
            ConcurrencyServiceProvider::class,
            FilesystemServiceProvider::class,
            RedisServiceProvider::class,
            QueueServiceProvider::class,
            ScheduleServiceProvider::class,
            // Deferred: each owns the bindings its own namespace used to have
            // registered for it by registerFacadeBindings(). `nitro optimize`
            // records what they provide, so production never loads them until
            // something resolves one.
            SupportServiceProvider::class,
            HttpServiceProvider::class,
            ContextServiceProvider::class,
            ProcessServiceProvider::class,
            TranslationServiceProvider::class,
            BroadcastServiceProvider::class,
            TestingServiceProvider::class,
            InertiaServiceProvider::class,
        ];
    }

    /**
     * Register a service provider, by class name or as a built instance.
     *
     * Idempotent: a provider already registered is returned untouched rather
     * than registered twice.
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

        $instance = is_string($provider)
            ? new $provider($this->container)
            : $provider;

        /*
         * Deferred providers: record what they provide and skip register()
         * entirely until one of those services is actually resolved. The
         * provider isn't bootable until that point either — boot() runs after
         * its on-demand register().
         */
        if ($instance->isDeferred()) {
            foreach ($instance->provides() as $service) {
                $this->deferredServices[$service] = $className;
            }
            $this->loadedProviders[$className] = $instance;
            return $instance;
        }

        /**
         * Emit point — provider.registering
         *
         * Once per non-deferred provider, before its register() runs. A
         * deferred provider raises this too, but only when something actually
         * resolves one of its services — which is the point of deferring it.
         * Payload: {@see ProviderEvent}.
         */
        $this->raise(CoreEvents::PROVIDER_REGISTERING, new ProviderEvent($className));

        $instance->register();

        $this->serviceProviders[] = $instance;
        $this->loadedProviders[$className] = $instance;

        if (method_exists($instance, 'boot')) {
            $this->bootableProviders[] = $instance;
        }

        /**
         * Emit point — provider.registered
         *
         * After register() returns and the provider has been recorded. Its
         * bindings exist but nothing has booted yet, so resolving a service
         * here may pull the rest of the graph in earlier than intended.
         * Payload: {@see ProviderEvent}.
         */
        $this->raise(CoreEvents::PROVIDER_REGISTERED, new ProviderEvent($className));

        return $instance;
    }

    /**
     * Called by the container when an unresolved abstract is requested.
     * Returns true if this resolves to a deferred provider and we successfully
     * registered (and booted, if applicable) it.
     *
     * Every service the provider offers is cleared from the deferred map before
     * its register() runs, so a re-entrant resolve from inside register() finds
     * nothing left to defer and cannot loop.
     */
    public function loadDeferredProvider(string $abstract): bool
    {
        if (!isset($this->deferredServices[$abstract])) {
            return false;
        }

        $providerClass = $this->deferredServices[$abstract];
        $instance = $this->loadedProviders[$providerClass] ?? new $providerClass($this->container);

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

    /**
     * Boot every registered provider that has a boot(), in registration order.
     *
     * Resolved through the container so a boot() signature's dependencies are
     * injected. Existence of the method was settled at registration time, so
     * nothing is re-checked per provider per request.
     */
    public function bootProviders(): void
    {
        foreach ($this->bootableProviders as $provider) {
            $name = is_object($provider) ? $provider::class : (string) $provider;

            /**
             * Emit point — provider.booting
             *
             * Once per bootable provider, immediately before its boot() runs.
             * Pairs with provider.booted to time a slow provider.
             * Payload: {@see ProviderEvent}.
             */
            $this->raise(CoreEvents::PROVIDER_BOOTING, new ProviderEvent($name));

            // Marked per provider, because bootProviders is one number and
            // usually the largest one — without this it says where the time
            // went but not which provider spent it.
            \Nitro\Debug\Timeline::mark(
                'boot ' . (strrchr($name, '\\') === false ? $name : substr(strrchr($name, '\\'), 1))
            );

            $this->container->call([$provider, 'boot']);

            /**
             * Emit point — provider.booted
             *
             * Once per bootable provider, after its boot() returns. A listener
             * waiting on one specific provider's services can act here rather
             * than waiting for app.bootstrapped.
             * Payload: {@see ProviderEvent}.
             */
            $this->raise(CoreEvents::PROVIDER_BOOTED, new ProviderEvent($name));
        }
    }

    /*
     * ── Lifecycle hooks: the public API ──────────────────────────────────────
     */

    /**
     * Append bootstrappers to the sequence {@see bootstrap()} will run.
     *
     * Appended, not replaced: the four core bootstrappers still run first, so
     * anything added here sees a loaded environment, configuration and
     * registered providers.
     *
     * @param array<int, class-string> $bootstrappers
     */
    public function bootstrapWith(array $bootstrappers): void
    {
        $this->bootstrappers = array_merge($this->bootstrappers, $bootstrappers);
    }

    /** Register a hook to run just before providers boot. */
    public function beforeBooting(callable $hook): void
    {
        $this->bootingHooks[] = $hook;
    }

    /**
     * Register a hook to run just after providers boot.
     *
     * Registering after the application has already booted runs the hook
     * immediately, so a caller never has to ask which side of boot it is on.
     */
    public function booted(callable $hook): void
    {
        if ($this->bootstrapped) {
            $hook($this);
        } else {
            $this->bootedHooks[] = $hook;
        }
    }

    /*
     * ── Lifecycle hooks: internal firing ─────────────────────────────────────
     */

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

    /*
     * ── Utilities ────────────────────────────────────────────────────────────
     */

    /** Get the path registry instance */
    public function paths(): PathRegistryContract
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

    /**
     * The current environment, or whether it is one of the given ones.
     *
     * Called with no arguments it answers "which environment"; with arguments it
     * answers "is it one of these", which is the question almost every caller
     * actually has. Without the second form that check gets written out longhand
     * at each site — `config('app.env') === 'production'` — and then written
     * slightly differently at the next one.
     *
     * Patterns may use * ('stag*' matches 'staging').
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

            if (str_contains($pattern, '*')
                && preg_match('#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#', $current) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    /** Whether the application is running in the local environment. */
    public function isLocal(): bool
    {
        return $this->environment('local') === true;
    }

    /** Whether the application is running in production. */
    public function isProduction(): bool
    {
        return $this->environment('production') === true;
    }

    /** Whether a test runner is driving the application. */
    public function runningUnitTests(): bool
    {
        return $this->environment('testing') === true;
    }

    /**
     * Whether the application is down for maintenance.
     *
     * Asks the MaintenanceMode service rather than stat'ing the file here, so
     * the down file's location is known in exactly one place.
     */
    public function isDownForMaintenance(): bool
    {
        return $this->container->resolve(MaintenanceMode::class)->active();
    }

    /**
     * Register a callback to run after the response has been sent.
     *
     * The seam for work that must not delay the response — writing an audit
     * row, closing a connection. The HTTP kernel fires these from terminate().
     */
    public function terminating(callable $callback): static
    {
        $this->terminatingCallbacks[] = $callback;

        return $this;
    }

    /**
     * Run the terminating callbacks.
     *
     * Each is isolated: one throwing must not stop the rest, because by this
     * point the response is already on the wire and there is nothing useful to
     * report to the client. Failures go to the exception handler instead.
     */
    public function terminate(): void
    {
        /**
         * Emit point — app.terminating
         *
         * After the response has been sent, before the terminating callbacks
         * run. Nothing said here can reach the client, which is exactly why
         * it is the right place for slow work: flushing metrics, writing a
         * log. Under a Thrust worker the process survives, so a listener must
         * not assume it is the last thing that will ever run.
         * Payload: none.
         */
        $this->raise(CoreEvents::APP_TERMINATING);

        foreach ($this->terminatingCallbacks as $callback) {
            try {
                $this->container->call($callback);
            } catch (\Throwable $exception) {
                $this->reportTerminationFailure($exception);
            }
        }

        $this->terminatingCallbacks = [];
    }

    /** Report a failure from a terminating callback, or swallow it if nothing can. */
    private function reportTerminationFailure(\Throwable $exception): void
    {
        try {
            $this->container->resolve(\Nitro\Exceptions\ExceptionHandler::class)->report($exception);
        } catch (\Throwable) {
            // Nothing left to report through; the response has already been sent.
        }
    }

    /** Check if the application is running in the console (CLI) */
    public function runningInConsole(): bool
    {
        return php_sapi_name() === 'cli' || php_sapi_name() === 'phpdbg';
    }
}
