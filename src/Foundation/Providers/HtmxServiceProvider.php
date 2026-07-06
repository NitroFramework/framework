<?php

namespace Nitro\Foundation\Providers;

use Nitro\Cache\CacheManager;
use Nitro\Foundation\Http\Kernel;
use Nitro\Foundation\Providers\ServiceProvider;
use Nitro\Htmx\HtmxComponentRenderer;
use Nitro\Htmx\HtmxKernel;
use Nitro\Htmx\Navigation\NitroNavigation;
use Nitro\Http\Request;
use Nitro\Routing\Router;
use Nitro\Htmx\State\ArrayStateStore;
use Nitro\Htmx\State\CacheStateStore;
use Nitro\Htmx\State\SessionStateStore;
use Nitro\Htmx\State\StateStore;
use Nitro\Htmx\Support\ArgumentResolver;
use Nitro\Htmx\Support\ComponentResolver;
use Nitro\Htmx\Support\HxEncryptor;
use Nitro\Htmx\Support\HxHelper;
use Nitro\Htmx\Support\HxObfuscator;
use Nitro\Htmx\Support\RequestGuard;
use Nitro\View\Blade;

/**
 * Wires the HTMX layer into the core via router macros and kernel lifecycle hooks.
 */
class HtmxServiceProvider extends ServiceProvider
{
    /**
     * Selection logic for the configured StateStore. Exposed as a static
     * factory so both the container binding and the test suite can call
     * the same code path — no risk of the binding drifting out of sync
     * with what the tests verify.
     */
    public static function makeStateStore($container): StateStore
    {
        return match (config('htmx.state.store', 'session')) {
            'cache'   => new CacheStateStore(
                $container->make(CacheManager::class),
                config('htmx.state.cache_driver'),
                config('htmx.state.ttl'),
            ),
            'array'   => new ArrayStateStore(),
            default   => new SessionStateStore(),
        };
    }

    public function register(): void
    {
        $this->container->singleton(StateStore::class, fn($c) => self::makeStateStore($c));

        $this->container->singleton(HxObfuscator::class, function ($container) {
            // PSR-4 convention: App\Htmx\Components\ → app/Htmx/Components.
            // Override via config('htmx.components_path') if your structure
            // doesn't follow the standard mapping.
            $ns = rtrim((string) config('htmx.component_namespace'), '\\');
            $defaultDir = $container->get('paths')->base(
                str_replace('\\', DIRECTORY_SEPARATOR, lcfirst($ns)),
            );

            return new HxObfuscator(
                config('htmx.obfuscation', true),
                config('app.key', ''),
                config('htmx.component_namespace'),
                config('htmx.components', []),
                config('htmx.components_path', $defaultDir),
            );
        });

        $this->container->singleton(HxEncryptor::class, function ($container) {
            return new HxEncryptor(
                config('htmx.encryption', true),
                config('app.key', ''),
            );
        });

        $this->container->singleton(HxHelper::class, function ($container) {
            return new HxHelper(
                $container->make(HxObfuscator::class),
                $container->make(HxEncryptor::class),
                config('htmx.route_prefix', '/hx'),
            );
        });

        $this->container->singleton(RequestGuard::class, function ($container) {
            return new RequestGuard(
                $container,
                config('htmx.csrf', true),
                config('htmx.check_hx_header', true),
            );
        });

        $this->container->singleton(ComponentResolver::class, function ($container) {
            return new ComponentResolver(
                $container,
                config('htmx.component_namespace'),
            );
        });

        $this->container->singleton(ArgumentResolver::class, function ($container) {
            return new ArgumentResolver($container);
        });

        $this->container->singleton(HtmxKernel::class, function ($container) {
            return new HtmxKernel(
                $container,
                $container->make(RequestGuard::class),
                $container->make(ComponentResolver::class),
                $container->make(ArgumentResolver::class),
            );
        });

        $this->container->singleton(HtmxComponentRenderer::class, function ($container) {
            return new HtmxComponentRenderer(
                $container,
                config('htmx.component_namespace'),
            );
        });

        // Registered here (not in boot) because routes are loaded during
        // RoutingServiceProvider::boot(), which runs before this provider's
        // boot() — the macro must exist before routes/web.php calls it.
        $this->registerRouterMacros();
    }

    public function boot(): void
    {
        $this->registerActionRoute();
        $this->registerBladeDirectives();
        $this->registerNavigationHook();
    }

    /**
     * Teach the core router how to register an HTMX component page route via
     * `$router->htmx($path, Component::class)`, without the router itself
     * depending on the HTMX layer.
     */
    protected function registerRouterMacros(): void
    {
        $container = $this->container;

        Router::macro('htmx', function (string $path, string $component, string $action = 'index') use ($container) {
            if (str_contains($component, '\\')) {
                // Cheap basename via strrchr — avoids spinning up ReflectionClass
                // just to read a class short name.
                $component = substr(strrchr($component, '\\'), 1);
            }

            // $this is bound to the Router instance, so the protected addRoute()
            // is reachable. The HTMX kernel is resolved lazily at request time.
            return $this->addRoute('GET', $path, function (Request $request) use ($container, $component, $action) {
                return $container->make(HtmxKernel::class)->handle($request, $component, $action, true);
            });
        });
    }

    /**
     * Attach Nitro-navigation fragment trimming to the HTTP kernel's
     * response-ready hook, keeping that logic out of the core kernel.
     */
    protected function registerNavigationHook(): void
    {
        $navigation = new NitroNavigation();

        $this->container->make(Kernel::class)->responseReady(
            static fn(Request $request, $response) => $navigation->prepare($request, $response)
        );
    }

    protected function registerActionRoute(): void
    {
        $router = $this->container->make('router');
        $prefix = config('htmx.route_prefix', '/hx');
        $methods = config('htmx.route_methods', ['POST', 'GET']);

        $router->match($methods, $prefix . '/{component}/{action}', function ($hashedComp, $hashedAction) {
            $obfuscator = $this->container->make(HxObfuscator::class);
            $realComponent = $obfuscator->reverseLookup($hashedComp);
            $realAction = $obfuscator->reverseActionLookup($realComponent, $hashedAction);

            return $this->container->make(HtmxKernel::class)
                ->handle($this->container->make('request'), $realComponent, $realAction);
        });
    }


    protected function registerBladeDirectives(): void
    {
        $this->registerComponentActionDirectives();
        $this->registerEmbeddingDirectives();
        $this->registerValidationDirectives();
    }

    private function registerComponentActionDirectives(): void
    {
        Blade::directive('hx', function ($expression) {
            return "<?php 
            \$__hxTagStack = \$__hxTagStack ?? [];
            \$__hxParams = $expression;
            \$__hxTagStack[] = \$__hxParams['tag'] ?? 'button';
            echo app(\Nitro\Htmx\Support\HxHelper::class)->compile(\$__hxParams); 
        ?>";
        });

        Blade::directive('endhx', function () {
            return "<?php echo '</' . array_pop(\$__hxTagStack) . '>'; ?>";
        });

        Blade::directive('hxAttr', function ($expression) {
            return "<?php 
            echo app(\Nitro\Htmx\Support\HxHelper::class)->compile(array_merge($expression, ['tag' => 'none'])); 
        ?>";
        });

       

      
    }

    private function registerEmbeddingDirectives(): void
    {
        Blade::directive('htmxComponent', function ($expression) {
            return "<?php echo app(\Nitro\Htmx\HtmxComponentRenderer::class)->render($expression); ?>";
        });

        Blade::directive('widget', function ($expression) {
            return "<?php echo app(\Nitro\Htmx\HtmxComponentRenderer::class)->render($expression); ?>";
        });
    }

    private function registerValidationDirectives(): void
    {
        Blade::directive('hxErrors', function ($expression) {
            return "<?php if ({$expression} && {$expression}->any()): ?>
<div class=\"hx-errors\" role=\"alert\">
    <ul>
        <?php foreach ({$expression}->all() as \$__err): ?>
            <li><?php echo htmlspecialchars(\$__err, ENT_QUOTES, 'UTF-8'); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>";
        });

        Blade::directive('hxError', function ($expression) {
            return "<?php if (isset(\$errors) && \$errors->has({$expression})): ?>
<span class=\"hx-field-error\"><?php echo htmlspecialchars(\$errors->first({$expression}), ENT_QUOTES, 'UTF-8'); ?></span>
<?php endif; ?>";
        });
    }
}
