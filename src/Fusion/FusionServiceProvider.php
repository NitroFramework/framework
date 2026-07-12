<?php

namespace Nitro\Fusion;

use Nitro\Foundation\Providers\ServiceProvider;
use Nitro\Fusion\Runtime\FusionRenderer;
use Nitro\Fusion\Runtime\FusionServer;
use Nitro\Http\Response;
use Nitro\Routing\Router;
use Nitro\View\Compiler\BladeCompiler;
use Nitro\View\Contracts\ViewEngine;

/**
 * Wires the Fusion layer into the app through the framework's extension seams —
 * a `Route::fusion()` router macro, the `fusion::` view namespace, Blade
 * directives, and the #[Server] RPC route — without touching any core class:
 *
 *  - `Route::fusion('/path', 'Component'[, 'your.view'][, $data])` — a routed
 *    full-page client component (see {@see registerRouterMacro()}).
 *  - `@fusion(Counter::class, [...])` — server-renders a #[Client] component for
 *    first paint (via {@see FusionRenderer}).
 *  - `@fusionScripts` — emits the built bundle + runtime + CSRF token (once, near
 *    </body>).
 *  - `POST config('fusion.call_uri')` — the #[Server] RPC endpoint (via {@see FusionServer}).
 *
 * The bundle and runtime JS themselves are plain public assets written by
 * `nitro fusion:build` to public/nitro/, so they need no route.
 */
class FusionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Normalize to a trailing-separator namespace whether config writes it
        // with or without one ('App\Fusion\Components' or '…\Components\').
        $namespace = rtrim((string) config('fusion.namespace', 'App\\Fusion\\Components'), '\\') . '\\';

        $this->container->singleton(
            FusionRenderer::class,
            fn ($c) => new FusionRenderer($c, $namespace)
        );
        $this->container->singleton(
            FusionServer::class,
            fn ($c) => new FusionServer($c, $namespace)
        );

        // Router macros must exist before routes load (during boot).
        $this->registerRouterMacro();
    }

    public function boot(): void
    {
        $this->registerViews();
        $this->registerDirectives();
        $this->registerServerEndpoint();
    }

    /** Register the fusion:: view namespace — the zero-config page shell lives here. */
    protected function registerViews(): void
    {
        $this->container->make(ViewEngine::class)->addNamespace('fusion', __DIR__ . '/views');
    }

    /**
     * Route::fusion('/path', 'Component'[, 'your.view'][, $data]) — a routed
     * full-page client component, mirroring Route::livewire / Route::htmx.
     *
     * With no view it renders into config('fusion.layout') via the fusion::page
     * shell (component fills the layout's section); pass a view to own the page
     * entirely (place @fusion($component) + @fusionScripts inside it).
     */
    protected function registerRouterMacro(): void
    {
        Router::macro('fusion', function (string $path, string $component, ?string $view = null, array $data = []) {
            // $this is bound to the Router; delegate to its tested view() route.
            if ($view !== null) {
                return $this->view($path, $view, array_merge(['component' => $component], $data));
            }

            return $this->view($path, 'fusion::page', array_merge([
                '__layout'    => config('fusion.layout', 'layouts.app'),
                '__section'   => config('fusion.layout_section', 'content'),
                '__component' => $component,
            ], $data));
        });
    }

    protected function registerDirectives(): void
    {
        BladeCompiler::registerCustomDirective(
            'fusion',
            fn (string $expression): string =>
                "<?php echo app(\\Nitro\\Fusion\\Runtime\\FusionRenderer::class)->render({$expression}); ?>"
        );

        BladeCompiler::registerCustomDirective(
            'fusionScripts',
            fn (string $expression): string => '<?php echo \\Nitro\\Fusion\\Runtime\\FusionRenderer::scripts(); ?>'
        );
    }

    protected function registerServerEndpoint(): void
    {
        $container = $this->container;
        $router = $container->make('router');

        $router->post(config('fusion.call_uri', '/nitro/fusion/call'), static function () use ($container): Response {
            $request = app('request');

            // CSRF: the runtime sends X-CSRF-Token; verify against the session token.
            $token = $request->header('X-CSRF-Token');
            if (! is_string($token) || $token === '' || ! hash_equals(csrf_token(), $token)) {
                abort(419, 'CSRF token mismatch.');
            }

            $payload = (array) $request->post();   // JSON body is decoded into the body bag
            $result = $container->make(FusionServer::class)->handle($payload);

            return Response::json($result);
        });
    }
}
