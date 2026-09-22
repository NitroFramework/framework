<?php

namespace Nitro\Foundation\Providers;

use Nitro\Container\Contracts\ClassResolver;
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
use Nitro\View\Contracts\MarkdownParser;
use Nitro\View\Markdown\Parser;
use Nitro\View\Support\ViewExtensions;
use Nitro\View\Contracts\TagCompiler;
use Nitro\View\Contracts\TemplateCache;
use Nitro\View\Contracts\TemplateCompiler;
use Nitro\View\Contracts\ViewComposerResolver;
use Nitro\View\Contracts\Engine;
use Nitro\View\Contracts\ViewFinder;
use Nitro\View\View;
use Nitro\View\Factory;
use Nitro\View\Engines\CompilerEngine;
use Nitro\View\FileViewFinder;
use Nitro\View\Support\ComposerResolver;

/**
 * Registers the Blade compiler, view engine, factory and component renderer.
 */
class ViewServiceProvider extends ServiceProvider
{
    protected string $directivesFile = 'directives.php';

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

    /** The concrete compilers, and the engine that drives them. */
    protected function registerCompilers(): void
    {
        $this->container->singleton(ComponentTagCompiler::class);
        $this->container->singleton(BladeCompiler::class);
        $this->container->singleton(ComposerResolver::class);
        /*
         * A closure rather than a plain singleton so the engine can be handed
         * the event bus it raises view.rendering/rendered on. build() autowires
         * without consulting this binding, so the engine is still constructed
         * exactly once and only when something actually renders — resolving it
         * in boot() instead would load the compiler on every request.
         */
        $this->container->singleton(CompilerEngine::class, function ($container) {
            $engine = $container->build(CompilerEngine::class);

            if ($engine instanceof ReceivesDispatcher) {
                $engine->setDispatcher($container->resolve(EventDispatcher::class));
            }

            return $engine;
        });
    }

    /**
     * Resolves view names to template files. A singleton because it memoizes
     * those resolutions, and because the namespaces a provider registers must
     * be visible to every later lookup.
     */
    protected function registerFinder(): void
    {
        $this->container->singleton(ViewFinder::class, function ($container) {
            return new FileViewFinder(
                $container->resolve('paths')->views(),
                ViewExtensions::from($container->resolve(ConfigRepository::class)),
            );
        });
    }

    /**
     * The bundled Markdown parser. Bound to the contract so an application can
     * put another one behind it without the view layer noticing.
     */
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
     * Which compiler each extension goes through, and the cache their output
     * lands in. Markdown wraps Blade rather than replacing it, so a `.md` page
     * keeps its directives.
     */
    protected function registerTemplateCache(): void
    {
        $this->container->singleton(TemplateCompilers::class, function ($container) {
            $blade    = $container->resolve(BladeCompiler::class);
            $registry = new TemplateCompilers($blade);

            $registry->register('md', new MarkdownCompiler($blade));
            $registry->register('markdown', new MarkdownCompiler($blade));

            return $registry;
        });

        $this->container->singleton(CompiledTemplateCache::class, function ($container) {
            return new CompiledTemplateCache(
                $container->resolve(BladeCompiler::class),
                $container->resolve(PathRegistry::class),
                $container->resolve(ConfigRepository::class),
                $container->resolve(TemplateCompilers::class),
            );
        });
    }

    /**
     * What @vite resolves. A singleton because the manifest is read from disk
     * once and then answers every entry on the page — and under a worker, once
     * for the life of the process.
     */
    protected function registerVite(): void
    {
        $this->container->singleton(\Nitro\View\Vite::class, function ($container) {
            $config = $container->resolve(ConfigRepository::class);

            return new \Nitro\View\Vite(
                publicPath: function_exists('public_path') ? public_path() : getcwd() . '/public',
                buildDirectory: (string) $config->get('vite.build_directory', 'build'),
                hotFile: (string) $config->get('vite.hot_file', 'hot'),
            );
        });

        $this->container->alias(\Nitro\View\Vite::class, 'vite');
    }

    /** Each contract routed to the concrete singleton registered above. */
    protected function registerContracts(): void
    {
        $this->container->singleton(TemplateCompiler::class, BladeCompiler::class);
        $this->container->singleton(TagCompiler::class, ComponentTagCompiler::class);
        $this->container->singleton(TemplateCache::class, CompiledTemplateCache::class);
        $this->container->singleton(Engine::class, CompilerEngine::class);
        $this->container->singleton(ComponentEngine::class, ComponentRenderer::class);
        $this->container->singleton(ViewComposerResolver::class, ComposerResolver::class);
    }

    /** The renderer, the factory and the Blade entry point. */
    protected function registerFactory(): void
    {
        // Lazy engine, to avoid circular resolution.
        $this->container->singleton(ComponentRenderer::class, function ($container) {
            return new ComponentRenderer(
                fn() => $container->resolve(Engine::class),
            );
        });

        $this->container->singleton(Factory::class, function ($container) {
            return new Factory(
                $container->resolve(Engine::class),
                $container->resolve(ClassResolver::class),
                $container->resolve(ViewComposerResolver::class),
            );
        });

        $this->container->singleton(Blade::class);

        $this->container->alias(Blade::class, 'view');
        $this->container->alias(Factory::class, 'view.factory');
    }

    public function boot(PathRegistry $paths): void
    {
        // Always register directives from config/directives.php with their real,
        // expression-aware callbacks. (Directives are intentionally not cached:
        // a callback's output depends on the invocation's $expression, so a
        // cached snapshot for one expression can't stand in for all calls.)
        $this->loadCustomDirectives($paths);
    }

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
