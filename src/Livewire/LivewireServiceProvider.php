<?php

namespace Nitro\Livewire;

use Nitro\Container\Contracts\CallableInvoker;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\Providers\ServiceProvider;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Livewire\Compilation\IslandCompiler;
use Nitro\Livewire\Compilation\LivewireTagCompiler;
use Nitro\Livewire\Features\SupportsFileUploads;
use Nitro\Livewire\Routing\LivewireRouteType;
use Nitro\Livewire\Runtime\LivewireManager;
use Nitro\Routing\Contracts\ExtendableRouter;
use Nitro\Routing\Contracts\RouterInterface as Router;
use Nitro\Routing\RouteTypes;
use Nitro\View\Blade;
use Nitro\View\Contracts\Engine;
use Nitro\View\Contracts\ViewFinder;

/**
 * Wires the Livewire layer into Nitro through the framework's extension seams —
 * a Router route for update commits, Blade directives, and a Request accessor —
 * without modifying any core class. The layer depends on Nitro's Container, View,
 * Request/Response, Session, Validation and Events through their public surface.
 */
class LivewireServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(LivewireManager::class, function ($container) {
            return new LivewireManager(
                resolver: $container->resolve(ClassResolver::class),
                invoker: $container->resolve(CallableInvoker::class),
                config: $container->resolve(ConfigRepository::class),
                engine: static fn (): Engine => $container->resolve(Engine::class),
            );
        });
        $this->container->alias(LivewireManager::class, 'livewire');

        /* Both must exist before routes load (during boot). */
        $this->registerRouteType();
        $this->registerRouterMacro();
    }

    /**
     * Teach the routing layer what a full-page component route is.
     *
     * The core router recognizes four kinds of route and none of them is this
     * one; rather than adding a fifth to the framework, the layer registers
     * its own and keeps the knowledge on this side of the line.
     */
    protected function registerRouteType(): void
    {
        $container = $this->container;

        $this->container->resolve(RouteTypes::class)->add(new LivewireRouteType(
            static fn (): LivewireManager => $container->resolve(LivewireManager::class),
        ));
    }

    /**
     * What this layer needs from Nitro, stated once.
     *
     * The Application calls boot() through the container, so the parameters
     * are injected — the helpers below are handed what they need rather than
     * reaching for it, and the router is resolved once instead of four times.
     *
     * The finder, not the engine: a namespace is a lookup path, and asking the
     * engine for one would build the compiler in boot() on every request.
     */
    public function boot(
        Router $router,
        ViewFinder $finder,
        ConfigRepository $config,
    ): void {
        $this->registerViews($finder);
        $this->registerRequestMacro();
        $this->registerAssetRoute($router);
        $this->registerUpdateRoute($router, $config);
        $this->registerUploadRoute($router);
        $this->registerBladeDirectives();
    }

    /** Register the livewire:: and livewire-sfc:: view namespaces. */
    protected function registerViews(ViewFinder $finder): void
    {
        $finder->addNamespace('livewire', __DIR__ . '/views');

        // Compiled single-file component views live here.
        $sfcDir = storage_path('cache/livewire-sfc');
        if (! is_dir($sfcDir)) {
            mkdir($sfcDir, 0775, true);
        }
        $finder->addNamespace('livewire-sfc', $sfcDir);
    }

    /**
     * Route::livewire('/path', 'component-name') — a routed full-page component.
     *
     * The component is registered by name rather than in a closure. A closure
     * cannot be serialized, and the route cache is all-or-nothing: one closure
     * route turns caching off for every route in the application, so a single
     * full-page component used to cost an app its route cache entirely.
     *
     * The verb is asked for, not assumed. Router::macro() named the framework's
     * concrete router, which made this whole layer unusable with any other one;
     * a router that cannot take a new verb is simply not given one, and the
     * application registers the route the long way instead.
     *
     * The handler calls get() rather than addRoute() — the same call one level
     * up, and on the contract, so its body works on any router.
     */
    protected function registerRouterMacro(): void
    {
        $router = $this->container->resolve(Router::class);

        if (! $router instanceof ExtendableRouter) {
            return;
        }

        $router->extend('livewire', function (string $path, string $name) {
            return $this->get($path, ['livewire' => $name]);
        });
    }

    /** Add Request::isLivewire(), which reads the header the client sends. */
    protected function registerRequestMacro(): void
    {
        Request::macro('isLivewire', function (): bool {
            /** @var Request $this */
            return $this->header('x-livewire') !== null;
        });
    }

    /**
     * GET /livewire/livewire.js — serve the client runtime from the framework
     * package itself (src/Livewire/Http/dist/livewire.js), the same way Livewire
     * serves its own dist file. The app never ships this file in public/; every
     * app runs the runtime bundled with its installed nitro/framework version,
     * so there are no per-app copies to keep in sync.
     *
     * Registered OUTSIDE the 'web' group on purpose: it is a public, cacheable
     * GET asset with no session or CSRF involvement.
     */
    protected function registerAssetRoute(Router $router): void
    {
        // The manager is a closure parameter, not a captured lookup: route
        // handlers are dispatched through the invoker, so it is built when the
        // asset is actually requested rather than when the route is declared.
        $router->get('/livewire/livewire.js', static function (LivewireManager $livewire): Response {
            return $livewire->scriptResponse();
        });
    }

    /** POST /livewire/update — the commit endpoint the client posts to. */
    protected function registerUpdateRoute(Router $router, ConfigRepository $config): void
    {
        $path = $config->get('livewire.update_uri', '/livewire/update');

        // Behind the 'web' group so CSRF is verified (VerifyCsrfToken reads the
        // X-CSRF-TOKEN header livewire.js sends). The snapshot checksum only
        // proves the state wasn't tampered with — it does NOT prove the request
        // came from the user's own session, so the token is the CSRF defense.
        // Plus any persistent middleware the application registered. An update
        // posts here rather than to the route that rendered the page, so
        // without this the page's own route middleware never runs on it — see
        // LivewireManager::addPersistentMiddleware().
        $router->group(['middleware' => array_merge(['web'], LivewireManager::persistentMiddleware())], function () use ($router, $path) {
            $router->post($path, static function (LivewireManager $livewire): Response {
                // The client posts a JSON commit body, which PHP does not fold
                // into $_POST — read and decode it directly.
                $payload = json_decode((string) file_get_contents('php://input'), true) ?: [];

                return Response::json($livewire->update($payload));
            });
        });
    }

    /**
     * POST /livewire/upload — receives wire:model file uploads, stores each in
     * the temporary directory with a sidecar of its client metadata, and returns
     * the generated temp filenames the client sets on the property.
     */
    protected function registerUploadRoute(Router $router): void
    {
        // Uploads are state-changing → behind 'web' for CSRF too (livewire.js
        // sends X-CSRF-TOKEN on the upload request).
        $router->group(['middleware' => array_merge(['web'], LivewireManager::persistentMiddleware())], function () use ($router) {
            $router->post('/livewire/upload', function (Request $request): Response {
                $dir = SupportsFileUploads::temporaryDirectory();

                $saved = [];
                // Source uploads from the Request seam, never $_FILES directly —
                // allFiles() returns the same $_FILES-shaped array (name/tmp_name/
                // size/type/error), so the multi- vs single-file handling below is
                // unchanged, but it's worker-safe and consistent with the rest.
                $files = $request->allFiles()['files'] ?? null;

                if (is_array($files) && is_array($files['name'])) {
                    for ($i = 0, $count = count($files['name']); $i < $count; $i++) {
                        if ((int) $files['error'][$i] !== UPLOAD_ERR_OK) {
                            continue;
                        }
                        $saved[] = $this->storeTemporaryUpload(
                            $dir, $files['tmp_name'][$i], $files['name'][$i],
                            (int) $files['size'][$i], (string) $files['type'][$i]
                        );
                    }
                } elseif (is_array($files) && (int) $files['error'] === UPLOAD_ERR_OK) {
                    $saved[] = $this->storeTemporaryUpload(
                        $dir, $files['tmp_name'], $files['name'],
                        (int) $files['size'], (string) $files['type']
                    );
                }

                return Response::json(['files' => $saved]);
            });
        });
    }

    /** Move one uploaded file into the temp dir under a random name + write its meta sidecar. */
    protected function storeTemporaryUpload(string $dir, string $tmpPath, string $original, int $size, string $type): string
    {
        return SupportsFileUploads::store($dir, $tmpPath, $original, $size, $type);
    }

    /**
     * @livewire('name', [...])  — mount a component
     * <livewire:name :prop="$x" /> — the tag form of the same
     * @livewireStyles / @livewireScripts — asset tags for the layout
     */
    protected function registerBladeDirectives(): void
    {
        // <livewire:name ... /> tag form → mount call, before other compilation.
        Blade::precompiler([LivewireTagCompiler::class, 'compile']);

        // @island('name', ...) … @endisland → deferred, isolated island render.
        Blade::precompiler([IslandCompiler::class, 'compile']);

        // @placeholder is consumed by the island precompiler; guard stray uses.
        Blade::directive('placeholder', static fn(): string => '');
        Blade::directive('endplaceholder', static fn(): string => '');

        Blade::directive('livewire', static fn(string $expression): string =>
            "<?php echo app('livewire')->mount({$expression}); ?>");

        Blade::directive('livewireStyles', static fn(): string =>
            "<?php echo app('livewire')->styles(); ?>");

        Blade::directive('livewireScripts', static fn(): string =>
            "<?php echo app('livewire')->scripts(); ?>");

        $this->registerScriptDirectives();
    }

    /**
     * The client-facing Blade helpers that pair with the livewire.js runtime:
     * inline scripts (@script), one-time asset injection (@assets), the $wire
     * JS handle (@this / @entangle / @js), and DOM directives that survive
     * wire:navigate (@persist / @teleport).
     */
    protected function registerScriptDirectives(): void
    {
        // @this / @entangle('prop') — the component's $wire handle (inside @script).
        Blade::directive('this', static fn(): string => '$wire');
        Blade::directive('entangle', static fn(string $expression): string => "\$wire.entangle({$expression})");

        // @script … @endscript — JS that runs once per component with $wire in scope.
        Blade::directive('script', static fn(): string => '<script type="text/nitro-script">');
        Blade::directive('endscript', static fn(): string => '</script>');

        // @assets … @endassets — markup injected into <head> once (dedup by content).
        Blade::directive('assets', static fn(): string => '<template wire:assets>');
        Blade::directive('endassets', static fn(): string => '</template>');

        // @persist('key') … @endpersist — DOM kept across wire:navigate swaps.
        Blade::directive('persist', static fn(string $expression): string =>
            '<div wire:persist="<?php echo ' . $expression . '; ?>">');
        Blade::directive('endpersist', static fn(): string => '</div>');

        // Client teleport uses the wire:teleport="#selector" attribute directly —
        // the @teleport directive name is owned by the core view engine's own
        // (server-side) teleport, so we don't shadow it.

        // @region('name') … @endregion — a re-renderable region. A commit that
        // originates inside it (or an #[RenderRegion] action) patches only that
        // region, not the whole component. Distinct from @island (isolated).
        Blade::directive('region', static fn(string $expression): string =>
            '<div wire:region="<?php echo ' . $expression . '; ?>">');
        Blade::directive('endregion', static fn(): string => '</div>');
    }
}
