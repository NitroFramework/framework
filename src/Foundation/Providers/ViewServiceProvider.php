<?php

namespace Nitro\Foundation\Providers;

use Nitro\Events\Contracts\Dispatcher as EventDispatcher;
use Nitro\Events\Contracts\ReceivesDispatcher;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\Contracts\PathRegistry;
use Nitro\View\Blade;
use Nitro\View\Compiler\BladeCompiler;
use Nitro\View\Compiler\CompiledTemplateCache;
use Nitro\View\Compiler\ComponentTagCompiler;
use Nitro\View\Compiler\MarkdownCompiler;
use Nitro\View\Compiler\TemplateCompilers;
use Nitro\View\Component\ComponentRenderer;
use Nitro\View\Contracts\ComponentEngine;
use Nitro\View\Contracts\Engine;
use Nitro\View\Contracts\MarkdownParser;
use Nitro\View\Contracts\TagCompiler;
use Nitro\View\Contracts\TemplateCache;
use Nitro\View\Contracts\TemplateCompiler;
use Nitro\View\Contracts\ViewComposerResolver;
use Nitro\View\Contracts\ViewFinder;
use Nitro\View\Engines\CompilerEngine;
use Nitro\View\Factory;
use Nitro\View\FileViewFinder;
use Nitro\View\Markdown\Parser;
use Nitro\View\Support\ComposerResolver;
use Nitro\View\Support\ViewExtensions;
use Nitro\View\Vite;

/**
 * Register the template compilers, view engine, factory and component renderer.
 */
class ViewServiceProvider extends ServiceProvider
{
    protected string $directivesFile = 'directives.php';

    /** Register the view layer's services. */
    public function register(): void
    {
        $this->registerCompilers();
        $this->registerFinder();
        $this->registerMarkdown();
        $this->registerTemplateCache();
        $this->registerVite();
        $this->registerContracts();
        $this->registerFactory();
    }

    /**
     * Register the compilers and the engine.
     *
     * The engine is given the event bus, and passes each nested view through the
     * factory so layouts, includes and components reach their creators and composers.
     */
    protected function registerCompilers(): void
    {
        $this->container->singleton(ComponentTagCompiler::class);
        $this->container->singleton(BladeCompiler::class);
        $this->container->singleton(ComposerResolver::class);

        $this->container->singleton(CompilerEngine::class, function ($container) {
            $engine = new CompilerEngine(
                $container->resolve(TemplateCache::class),
                $container->resolve(ComponentEngine::class),
                static fn (): BladeCompiler => $container->resolve(BladeCompiler::class),
                $container->resolve(TagCompiler::class),
                $container->resolve(PathRegistry::class),
                $container->resolve(ConfigRepository::class),
                $container->resolve(ViewFinder::class),
            );

            if ($engine instanceof ReceivesDispatcher) {
                $engine->setDispatcher($container->resolve(EventDispatcher::class));
            }

            $factory = null;

            $engine->prepareNestedViewsWith(
                static function (string $view, array $data) use ($container, &$factory): array {
                    $factory ??= $container->resolve(Factory::class);

                    return $factory->composed($view, $data);
                }
            );

            return $engine;
        });
    }

    /** Register the finder that resolves view names to template files. */
    protected function registerFinder(): void
    {
        $this->container->singleton(ViewFinder::class, function ($container) {
            return new FileViewFinder(
                $container->resolve('paths')->views(),
                ViewExtensions::from($container->resolve(ConfigRepository::class)),
            );
        });
    }

    /** Register the Markdown parser behind its contract. */
    protected function registerMarkdown(): void
    {
        $this->container->singleton(MarkdownParser::class, function ($container) {
            $config  = $container->resolve(ConfigRepository::class);
            $baseUrl = $config->get('view.markdown.base_url') ?? $config->get('app.url');

            return new Parser(
                allowHtml: (bool) $config->get('view.markdown.allow_html', false),
                hardBreaks: (bool) $config->get('view.markdown.hard_breaks', false),
                linkAttributes: (array) $config->get('view.markdown.link_attributes', []),
                baseUrl: is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : null,
                fragments: (bool) $config->get('view.markdown.fragments', false),
            );
        });
    }

    /**
     * Register the compiler for each template extension and the compiled-template cache.
     *
     * Markdown wraps Blade, so a .md page keeps its directives. Each compiler is built
     * only when a template actually needs compiling.
     */
    protected function registerTemplateCache(): void
    {
        $this->container->singleton(TemplateCompilers::class, function ($container) {
            $blade = static fn (): BladeCompiler => $container->resolve(BladeCompiler::class);

            $registry = new TemplateCompilers($blade);

            $registry->register('md', static fn (): MarkdownCompiler => new MarkdownCompiler($blade()));
            $registry->register('markdown', static fn (): MarkdownCompiler => new MarkdownCompiler($blade()));

            return $registry;
        });

        $this->container->singleton(CompiledTemplateCache::class, function ($container) {
            return new CompiledTemplateCache(
                static fn (): BladeCompiler => $container->resolve(BladeCompiler::class),
                $container->resolve(PathRegistry::class),
                $container->resolve(ConfigRepository::class),
                $container->resolve(TemplateCompilers::class),
            );
        });
    }

    /** Register the Vite manifest reader behind @vite. */
    protected function registerVite(): void
    {
        $this->container->singleton(Vite::class, function ($container) {
            $config = $container->resolve(ConfigRepository::class);

            return new Vite(
                publicPath: function_exists('public_path') ? public_path() : getcwd() . '/public',
                buildDirectory: (string) $config->get('vite.build_directory', 'build'),
                hotFile: (string) $config->get('vite.hot_file', 'hot'),
            );
        });

        $this->container->alias(Vite::class, 'vite');
    }

    /** Bind each view contract to its implementation. */
    protected function registerContracts(): void
    {
        $this->container->singleton(TemplateCompiler::class, BladeCompiler::class);
        $this->container->singleton(TagCompiler::class, ComponentTagCompiler::class);
        $this->container->singleton(TemplateCache::class, CompiledTemplateCache::class);
        $this->container->singleton(Engine::class, CompilerEngine::class);
        $this->container->singleton(ComponentEngine::class, ComponentRenderer::class);
        $this->container->singleton(ViewComposerResolver::class, ComposerResolver::class);
    }

    /** Register the component renderer, the factory and the Blade entry point. */
    protected function registerFactory(): void
    {
        $this->container->singleton(ComponentRenderer::class, function ($container) {
            return new ComponentRenderer(
                fn() => $container->resolve(Engine::class),
            );
        });

        $this->container->singleton(Factory::class, function ($container) {
            return new Factory(
                $container->resolve(Engine::class),
                $container->resolve(ViewComposerResolver::class),
            );
        });

        $this->container->singleton(Blade::class);

        $this->container->alias(Blade::class, 'view');
        $this->container->alias(Factory::class, 'view.factory');
    }

    /** Register the application's custom directives. */
    public function boot(PathRegistry $paths): void
    {
        $this->loadCustomDirectives($paths);
    }

    /** Run the callback config/directives.php returns, which registers the application's directives. */
    protected function loadCustomDirectives(PathRegistry $paths): void
    {
        $directivesFile = $paths->config($this->directivesFile);

        if (is_file($directivesFile)) {
            $directiveLoader = require $directivesFile;

            if (is_callable($directiveLoader)) {
                $directiveLoader($this->container);
            }
        }
    }
}
