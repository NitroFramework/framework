<?php

namespace Nitro\Livewire\Runtime;

use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Livewire\Attributes\Layout;
use Nitro\Livewire\Component;
use Nitro\Livewire\Features\SupportsLazyLoading;
use Nitro\Livewire\Hooks\UserHooks;
use Nitro\Livewire\Snapshot\Snapshotter;
use Nitro\View\Contracts\ViewEngine;
use ReflectionObject;

/**
 * The FIRST render of a component — the only pass that has no snapshot to start
 * from, so it is the one that runs mount().
 *
 * Three shapes come out of here: an inline mount (@livewire / <livewire:…>), a
 * full page (Route::livewire, wrapping the component in its #[Layout]), and the
 * lazy variant of either, where the placeholder is painted now and mount() is
 * deferred to an immediate follow-up commit with the parameters signed into the
 * memo.
 */
class Mounter
{
    public function __construct(
        protected ContainerInterface $container,
        protected ComponentRegistry $registry,
        protected Renderer $renderer,
        protected Snapshotter $snapshotter,
    ) {}

    /**
     * First render of a component to HTML with its initial snapshot embedded on
     * the root element.
     */
    public function mount(string $name, array $params = [], array $slots = []): string
    {
        $component = $this->registry->make($name);

        if ($slots !== []) {
            $component->setSlots($slots);
        }

        return $this->renderNew($component, $params);
    }

    /** Boot, mount (or defer for lazy), render, and wrap a fresh component instance. */
    public function renderNew(Component $component, array $params): string
    {
        $component->hooks()->boot();
        UserHooks::call($component, 'boot');

        $lazy = $component->hooks()->get(SupportsLazyLoading::class);

        // Lazy: paint a placeholder now and defer mount()/render to a follow-up
        // commit. The mount params ride along in the (signed) memo.
        if ($lazy->isLazy()) {
            $snapshot = $this->snapshotter->snapshot($component, $lazy->deferMemo($params));

            return $this->renderer->wrapRoot($lazy->placeholder(), $component->getId(), $snapshot);
        }

        if (method_exists($component, 'mount')) {
            $this->container->call([$component, 'mount'], $params);
        }

        $component->hooks()->mount($params);
        UserHooks::call($component, 'booted');

        // Initial render: all islands render (or their placeholder if lazy/defer).
        $component->beginIslandRender(true);

        $html = $this->renderer->render($component);
        $snapshot = $this->snapshotter->snapshot($component);

        return $this->renderer->wrapRoot($html, $component->getId(), $snapshot);
    }

    /**
     * Render a component as a full page — its HTML injected into the layout
     * declared by #[Layout(...)] (or the configured default). Used for routed
     * full-page components (Route::livewire(...)).
     */
    public function page(string $name, array $params = []): string
    {
        $component = $this->registry->make($name);
        $layout = $this->layoutFor($component);
        $html = $this->renderNew($component, $params);

        if ($layout === null) {
            return $html;
        }

        return $this->container->createOrResolve(ViewEngine::class)->render('livewire::page', [
            '__layout'  => $layout[0],
            '__section' => $layout[1],
            '__slot'    => $html,
        ]);
    }

    /** Resolve a component's layout: #[Layout] attribute, else config default. */
    protected function layoutFor(Component $component): ?array
    {
        $attributes = (new ReflectionObject($component))->getAttributes(Layout::class);

        if ($attributes !== []) {
            $layout = $attributes[0]->newInstance();
            return [$layout->layout, $layout->section];
        }

        $default = config('livewire.layout');

        return $default ? [$default, config('livewire.layout_section', 'content')] : null;
    }
}
