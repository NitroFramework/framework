<?php

namespace Nitro\Livewire;

use Nitro\Foundation\Providers\ServiceProvider;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Routing\Router;
use Nitro\View\Blade;
use Nitro\View\Contracts\ViewEngine;

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
        $this->container->singleton(LivewireManager::class, function ($c) {
            return new LivewireManager($c);
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
        $engine = $this->container->make(ViewEngine::class);
        $engine->addNamespace('livewire', __DIR__ . '/views');

        // Compiled single-file component views live here.
        $sfcDir = storage_path('cache/livewire-sfc');
        if (! is_dir($sfcDir)) {
            mkdir($sfcDir, 0775, true);
        }
        $engine->addNamespace('livewire-sfc', $sfcDir);
    }

    /** Route::livewire('/path', 'component-name') — a routed full-page component. */
    protected function registerRouterMacro(): void
    {
        $container = $this->container;

        Router::macro('livewire', function (string $path, string $name) use ($container) {
            return $this->addRoute('GET', $path, function () use ($container, $name): Response {
                return Response::html($container->make('livewire')->page($name));
            });
        });
    }

    /** Classify the incoming update commits, mirroring Request::isHtmx(). */
    protected function registerRequestMacro(): void
    {
        Request::macro('isLivewire', function (): bool {
            /** @var Request $this */
            return $this->header('x-livewire') !== null;
        });
    }

    /**
     * GET /livewire/livewire.js — serve the client runtime from the framework
     * package itself (src/Livewire/dist/livewire.js), the same way Livewire
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
        $router = $this->container->make('router');

        $router->get('/livewire/livewire.js', function () use ($container): Response {
            return $container->make(LivewireManager::class)->scriptResponse();
        });
    }

    /** POST /livewire/update — the commit endpoint the client posts to. */
    protected function registerUpdateRoute(): void
    {
        $container = $this->container;
        $router = $this->container->make('router');
        $path = config('livewire.update_uri', '/livewire/update');

        // Behind the 'web' group so CSRF is verified (VerifyCsrfToken reads the
        // X-CSRF-TOKEN header livewire.js sends). The snapshot checksum only
        // proves the state wasn't tampered with — it does NOT prove the request
        // came from the user's own session, so the token is the CSRF defense.
        $router->group(['middleware' => ['web']], function () use ($router, $path, $container) {
            $router->post($path, function () use ($container): Response {
                // The client posts a JSON commit body, which PHP does not fold
                // into $_POST — read and decode it directly.
                $payload = json_decode((string) file_get_contents('php://input'), true) ?: [];

                $result = $container->make(LivewireManager::class)->update($payload);

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
        $router = $this->container->make('router');

        // Uploads are state-changing → behind 'web' for CSRF too (livewire.js
        // sends X-CSRF-TOKEN on the upload request).
        $router->group(['middleware' => ['web']], function () use ($router) {
            $router->post('/livewire/upload', function (): Response {
                $dir = storage_path('app/' . TemporaryUploadedFile::TMP_DIR);
                if (! is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }

                $saved = [];
                $files = $_FILES['files'] ?? null;

                if (is_array($files) && is_array($files['name'])) {
                    for ($i = 0, $n = count($files['name']); $i < $n; $i++) {
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
        $extension = pathinfo($original, PATHINFO_EXTENSION);
        $name = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');

        move_uploaded_file($tmpPath, $dir . '/' . $name);
        file_put_contents(
            $dir . '/' . $name . '.meta.json',
            json_encode(['name' => $original, 'size' => $size, 'type' => $type])
        );

        return $name;
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
        // @js($value) — a PHP value as a safe JS literal.
        Blade::directive('js', static fn(string $expression): string =>
            "<?php echo \\Nitro\\Livewire\\Js::from({$expression}); ?>");

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
