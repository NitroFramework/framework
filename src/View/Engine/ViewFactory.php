<?php

namespace Nitro\View\Engine;

use Nitro\Container\Container;
use Nitro\View\Contracts\ViewEngine;
use Nitro\View\Contracts\ViewComposerResolver;

/**
 * Creates View instances and exposes rendering, sharing and composer APIs over the engine.
 */
class ViewFactory
{
    private array $shared = [];

    public function __construct(
        private ViewEngine $renderer,
        private Container $container,
        private ViewComposerResolver $composerResolver,
    ) {}

    public function make(string $template, array $data = []): View
    {
        $view = new View($template, $data);
        $view->setFactory($this);
        return $view;
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function getShared(): array
    {
        return $this->shared;
    }

    public function composer(string|array $templates, callable|string $composer): void
    {
        $this->composerResolver->register($templates, $composer);
    }

    /**
     * The single orchestration point: composers → shared data → engine.
     */
    public function renderView(View $view): string
    {
        $this->composerResolver->fire($view, $this->container);

        $finalData = array_merge($this->shared, $view->getData());

        return $this->renderer->render($view->template(), $finalData);
    }

    // --- Methods that Blade delegates here ---

    public function renderFragment(string $view, string $fragment, array $data = []): string
    {
        $finalData = array_merge($this->shared, $data);
        return $this->renderer->renderFragment($view, $fragment, $finalData);
    }

    public function renderFragments(string $view, array $fragments, array $data = []): string
    {
        $finalData = array_merge($this->shared, $data);
        return $this->renderer->renderFragments($view, $fragments, $finalData);
    }

    public function compileOnly(string $view): void
    {
        $this->renderer->compileOnly($view);
    }

    public function clearCache(): void
    {
        $this->renderer->clearCache();
    }

    public function clearViewCache(string $view): void
    {
        $this->renderer->clearViewCache($view);
    }

    public function getCacheStats(): array
    {
        return $this->renderer->getCacheStats();
    }

    public function hasSection(string $name): bool
    {
        return $this->renderer->hasSection($name);
    }

    public function getSection(string $name, string $default = ''): string
    {
        return $this->renderer->yieldContent($name, $default);
    }

    public function getAllSections(): array
    {
        return $this->renderer->getAllSections();
    }

    public function exists(string $view): bool
    {
        return $this->renderer->viewExists($view);
    }

    // ViewFactory.php
    public function renderPartial(string $view, array $data = []): string
    {
        $finalData = array_merge($this->shared, $data);
        return $this->renderer->renderPartial($view, $finalData);
    }

    public function forceSection(string $name, string $content): void
    {
        $this->renderer->forceSection($name, $content);
    }

    public function getRenderer(): ViewRenderer
    {
        return $this->renderer;
    }
}
