<?php

namespace Nitro\View;

use InvalidArgumentException;
use Nitro\Container\Container;
use Nitro\View\Contracts\ViewComposerResolver;
use Nitro\View\Contracts\Engine;
use Nitro\View\Contracts\Factory as FactoryContract;

/**
 * The view layer's front door: chooses views, holds the data every view sees,
 * and owns the one place composers, shared data and the engine meet.
 */
class Factory implements FactoryContract
{
    /**
     * Data made available to every view rendered through this factory.
     *
     * @var array<string, mixed>
     */
    private array $shared = [];

    /**
     * @param Engine           $renderer         Resolves and renders templates.
     * @param Container            $container        Resolves composers named by class.
     * @param ViewComposerResolver $composerResolver Holds and fires those composers.
     */
    public function __construct(
        private Engine $renderer,
        private Container $container,
        private ViewComposerResolver $composerResolver,
    ) {
    }

    // ─── Choosing a view ──────────────────────────────────

    /**
     * Choose a view, without rendering it yet.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $mergeData Defaults that $data overrides.
     */
    public function make(string $template, array $data = [], array $mergeData = []): View
    {
        return (new View($template, array_merge($mergeData, $data)))->setFactory($this);
    }

    /**
     * Choose the first of several candidate views that exists.
     *
     * How a caller expresses "the application's version of this if it has
     * published one, otherwise the package's": name both, most specific first.
     *
     * @param  array<int, string>   $views
     * @param  array<string, mixed> $data
     * @param  array<string, mixed> $mergeData
     * @throws InvalidArgumentException When none of them exist.
     */
    public function first(array $views, array $data = [], array $mergeData = []): View
    {
        foreach ($views as $view) {
            if ($this->exists($view)) {
                return $this->make($view, $data, $mergeData);
            }
        }

        throw new InvalidArgumentException(
            'None of the views in [' . implode(', ', $views) . '] exist.'
        );
    }

    /**
     * Get a view instance for a template addressed by absolute path.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $mergeData
     */
    public function file(string $path, array $data = [], array $mergeData = []): View
    {
        return $this->make($path, $data, $mergeData);
    }

    /**
     * Whether a view name resolves to a template in any registered directory.
     */
    public function exists(string $view): bool
    {
        return $this->renderer->viewExists($view);
    }

    // ─── Rendering ────────────────────────────────────────

    /**
     * Render a chosen view.
     *
     * The single orchestration point: composers run, shared data is merged
     * beneath the view's own, then the engine renders.
     */
    public function renderView(View $view): string
    {
        $this->composerResolver->fire($view, $this->container);

        return $this->renderer->render(
            $view->name(),
            array_merge($this->shared, $view->getData())
        );
    }

    /**
     * Render a view only when the condition holds, and an empty string when it
     * does not.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $mergeData
     */
    public function renderWhen(bool $condition, string $view, array $data = [], array $mergeData = []): string
    {
        return $condition ? $this->make($view, $data, $mergeData)->render() : '';
    }

    /**
     * The inverse of {@see renderWhen()}.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $mergeData
     */
    public function renderUnless(bool $condition, string $view, array $data = [], array $mergeData = []): string
    {
        return $this->renderWhen(! $condition, $view, $data, $mergeData);
    }

    /**
     * Render a view without layout resolution, for one template included inside
     * another.
     *
     * @param array<string, mixed> $data
     */
    public function renderPartial(string $view, array $data = []): string
    {
        return $this->renderer->renderPartial($view, array_merge($this->shared, $data));
    }

    /**
     * Render one named fragment of a view rather than the whole template.
     *
     * @param array<string, mixed> $data
     */
    public function renderFragment(string $view, string $fragment, array $data = []): string
    {
        return $this->renderer->renderFragment($view, $fragment, array_merge($this->shared, $data));
    }

    /**
     * Render several named fragments of a view in one pass.
     *
     * @param array<int, string>   $fragments
     * @param array<string, mixed> $data
     */
    public function renderFragments(string $view, array $fragments, array $data = []): string
    {
        return $this->renderer->renderFragments($view, $fragments, array_merge($this->shared, $data));
    }

    // ─── Shared data and composers ────────────────────────

    /**
     * Make a value available to every view rendered from here on.
     */
    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /**
     * The data every view will see.
     *
     * @return array<string, mixed>
     */
    public function getShared(): array
    {
        return $this->shared;
    }

    /**
     * Register a composer to run before the matching views render.
     *
     * @param string|array<int, string> $templates View names, `prefix.*`, or `*`.
     * @param callable|string           $composer  A callable, or a class name
     *                                             resolved from the container.
     */
    public function composer(string|array $templates, callable|string $composer): void
    {
        $this->composerResolver->register($templates, $composer);
    }

    // ─── Where views are looked for ───────────────────────

    /**
     * Register directories a namespace resolves against, searched in order.
     *
     * @param string|array<int, string> $paths
     */
    public function addNamespace(string $namespace, string|array $paths): void
    {
        $this->renderer->addNamespace($namespace, $paths);
    }

    /**
     * Register directories ahead of a namespace's existing ones, so they are
     * searched first.
     *
     * @param string|array<int, string> $paths
     */
    public function prependNamespace(string $namespace, string|array $paths): void
    {
        $this->renderer->prependNamespace($namespace, $paths);
    }

    /**
     * Replace whatever directories a namespace currently resolves against.
     *
     * @param string|array<int, string> $paths
     */
    public function replaceNamespace(string $namespace, string|array $paths): void
    {
        $this->renderer->replaceNamespace($namespace, $paths);
    }

    /**
     * Add a directory searched for views that name no namespace.
     */
    public function addLocation(string $path): void
    {
        $this->renderer->addLocation($path);
    }

    /**
     * Add a directory searched before those already registered.
     */
    public function prependLocation(string $path): void
    {
        $this->renderer->prependLocation($path);
    }

    /**
     * The directories a view naming no namespace is searched for, in order.
     *
     * @return array<int, string>
     */
    public function getLocations(): array
    {
        return $this->renderer->getLocations();
    }

    // ─── Sections ─────────────────────────────────────────

    /**
     * Whether a section has been defined during this render.
     */
    public function hasSection(string $name): bool
    {
        return $this->renderer->hasSection($name);
    }

    /**
     * The content of a section, or $default when it was never defined.
     */
    public function getSection(string $name, string $default = ''): string
    {
        return $this->renderer->yieldContent($name, $default);
    }

    /**
     * Every section defined during this render, keyed by name.
     *
     * @return array<string, string>
     */
    public function getAllSections(): array
    {
        return $this->renderer->getAllSections();
    }

    /**
     * Set a section's content directly, rather than by capturing output.
     */
    public function forceSection(string $name, string $content): void
    {
        $this->renderer->forceSection($name, $content);
    }

    // ─── Compiled templates ───────────────────────────────

    /**
     * Compile a view to its cached PHP form without rendering it.
     */
    public function compileOnly(string $view): void
    {
        $this->renderer->compileOnly($view);
    }

    /**
     * Discard every compiled template.
     */
    public function clearCache(): void
    {
        $this->renderer->clearCache();
    }

    /**
     * Discard the compiled form of one view.
     */
    public function clearViewCache(string $view): void
    {
        $this->renderer->clearViewCache($view);
    }

    /**
     * Counts and sizes describing the compiled template cache.
     *
     * @return array<string, mixed>
     */
    public function getCacheStats(): array
    {
        return $this->renderer->getCacheStats();
    }

    /**
     * The engine behind this factory.
     *
     * For the compiled-template directives, which call back into the renderer
     * while a template runs. Application code should not need it.
     */
    public function getRenderer(): Engine
    {
        return $this->renderer;
    }
}
