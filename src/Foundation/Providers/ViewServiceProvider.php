<?php

namespace Nitro\Foundation\Providers;

use Nitro\Foundation\PathRegistry;
use Nitro\View\Blade;
use Nitro\View\Compiler\BladeCompiler;
use Nitro\View\Compiler\CompiledTemplateCache;
use Nitro\View\Compiler\ComponentTagCompiler;
use Nitro\View\Component\ComponentRenderer;
use Nitro\View\Engine\ViewRenderer;
use Nitro\View\Engine\ViewFactory;
use Nitro\View\Engine\View;
use Nitro\View\Support\ComposerResolver;

// New contract imports:
use Nitro\View\Contracts\TemplateCompiler;
use Nitro\View\Contracts\TagCompiler;
use Nitro\View\Contracts\TemplateCache;
use Nitro\View\Contracts\ViewEngine;
use Nitro\View\Contracts\ComponentEngine;
use Nitro\View\Contracts\ViewComposerResolver;

/**
 * Registers the Blade compiler, view engine, factory and component renderer.
 */
class ViewServiceProvider extends ServiceProvider
{
    protected string $directivesFile = 'directives.php';

    public function register(): void
    {
        // ── Core compilers & managers (concrete singletons) ──
        $this->container->singleton(ComponentTagCompiler::class, ComponentTagCompiler::class);
        $this->container->singleton(BladeCompiler::class, BladeCompiler::class);
        $this->container->singleton(CompiledTemplateCache::class, CompiledTemplateCache::class);
        $this->container->singleton(ComposerResolver::class, ComposerResolver::class);
        $this->container->singleton(ViewRenderer::class, ViewRenderer::class);

        // ── Interface → concrete (route to the singleton above) ──
        $this->container->singleton(TemplateCompiler::class, BladeCompiler::class);
        $this->container->singleton(TagCompiler::class, ComponentTagCompiler::class);
        $this->container->singleton(TemplateCache::class, CompiledTemplateCache::class);
        $this->container->singleton(ViewEngine::class, ViewRenderer::class);
        $this->container->singleton(ComponentEngine::class, ComponentRenderer::class);
        $this->container->singleton(ViewComposerResolver::class, ComposerResolver::class);

        // ── Component renderer (lazy to avoid circular resolution) ──
        $this->container->singleton(ComponentRenderer::class, function ($container) {
            return new ComponentRenderer(
                fn() => $container->get(ViewEngine::class),
            );
        });

        // ── Factory ──
        $this->container->singleton(ViewFactory::class, function ($container) {
            return new ViewFactory(
                $container->get(ViewEngine::class),
                $container,
                $container->get(ViewComposerResolver::class),
            );
        });

        // ── Facade ──
        $this->container->singleton(Blade::class, Blade::class);

        // ── Aliases ──
        $this->container->alias('view', Blade::class);
        $this->container->alias('view.factory', ViewFactory::class);
    }

    public function boot(): void
    {
        // Always register directives from config/directives.php with their real,
        // expression-aware callbacks. (Directives are intentionally not cached:
        // a callback's output depends on the invocation's $expression, so a
        // cached snapshot for one expression can't stand in for all calls.)
        $paths = $this->container->get(PathRegistry::class);
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
