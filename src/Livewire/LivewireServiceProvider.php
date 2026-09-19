<?php

namespace Nitro\Livewire;

use Nitro\Foundation\Providers\ServiceProvider;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Livewire\Compilation\IslandCompiler;
use Nitro\Livewire\Compilation\LivewireTagCompiler;
use Nitro\Livewire\Features\SupportsFileUploads;
use Nitro\Livewire\Runtime\LivewireManager;
use Nitro\Routing\Router;
use Nitro\View\Blade;
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
            return new LivewireManager($container);
        });
        $this->container->alias('livewire', LivewireManager::class);

        // Router macros must exist before routes load (during boot).
        $this->registerRouterMacro();
    }

    public function boot(): void
    {
        $this->registerViews();
        $this->registerRequestMacro();
        $this->registerAssetRoute();
        $this->registerUpdateRoute();
        $this->registerUploadRoute();
        $this->registerBladeDirectives();
    }

    /** Register the livewire:: and livewire-sfc:: view namespaces. */
    protected function registerViews(): void
    {
        // The finder, not the engine: a namespace is a lookup path, and asking
        // the engine for one would build the compiler in boot() on every request.
        $finder = $this->container->createOrResolve(ViewFinder::class);
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
     */
    protected function registerRouterMacro(): void
    {
        Router::macro('livewire', function (string $path, string $name) {
            return $this->addRoute('GET', $path, ['livewire' => $name]);
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
    protected function registerAssetRoute(): void
    {
        $container = $this->container;
        $router = $this->container->createOrResolve('router');

        $router->get('/livewire/livewire.js', function () use ($container): Response {
            return $container->createOrResolve(LivewireManager::class)->scriptResponse();
        });
    }

    /** POST /livewire/update — the commit endpoint the client posts to. */
    protected function registerUpdateRoute(): void
    {
        $container = $this->container;
        $router = $this->container->createOrResolve('router');
        $path = config('livewire.update_uri', '/livewire/update');

        // Behind the 'web' group so CSRF is verified (VerifyCsrfToken reads the
        // X-CSRF-TOKEN header livewire.js sends). The snapshot checksum only
        // proves the state wasn't tampered with — it does NOT prove the request
        // came from the user's own session, so the token is the CSRF defense.
        // Plus any persistent middleware the application registered. An update
        // posts here rather than to the route that rendered the page, so
        // without this the page's own route middleware never runs on it — see
        // LivewireManager::addPersistentMiddleware().
        $router->group(['middleware' => array_merge(['web'], LivewireManager::persistentMiddleware())], function () use ($router, $path, $container) {
            $router->post($path, function () use ($container): Response {
                // The client posts a JSON commit body, which PHP does not fold
                // into $_POST — read and decode it directly.
                $payload = json_decode((string) file_get_contents('php://input'), true) ?: [];

                $result = $container->createOrResolve(LivewireManager::class)->update($payload);

                return Response::json($result);
            });
        });
    }

    /**
     * POST /livewire/upload — receives wire:model file uploads, stores each in
     * the temporary directory with a sidecar of its client metadata, and returns
     * the generated temp filenames the client sets on the property.
     */
    protected function registerUploadRoute(): void
    {
        $router = $this->container->createOrResolve('router');

        // Uploads are state-changing → behind 'web' for CSRF too (livewire.js
        // sends X-CSRF-TOKEN on the upload request).
        $router->group(['middleware' => array_merge(['web'], LivewireManager::persistentMiddleware())], function () use ($router) {
            $router->post('/livewire/upload', function (): Response {
                $dir = SupportsFileUploads::temporaryDirectory();

                $saved = [];
                // Source uploads from the Request seam, never $_FILES directly —
                // allFiles() returns the same $_FILES-shaped array (name/tmp_name/
                // size/type/error), so the multi- vs single-file handling below is
                // unchanged, but it's worker-safe and consistent with the rest.
                $files = $this->container->createOrResolve('request')->allFiles()['files'] ?? null;

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
