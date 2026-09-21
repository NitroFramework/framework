<?php

namespace Nitro\Livewire\Runtime;

use Closure;
use Nitro\Container\Contracts\CallableInvoker;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Livewire\Attributes\Layout;
use Nitro\Livewire\Component;
use Nitro\Livewire\Features\SupportsLazyLoading;
use Nitro\Livewire\Hooks\UserHooks;
use Nitro\Livewire\Snapshot\Snapshotter;
use Nitro\View\Contracts\Engine;
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
    /** @param Closure(): Engine $engine Built only when a full page needs its layout rendered. */
    public function __construct(
        protected CallableInvoker $invoker,
        protected Closure $engine,
        protected ComponentRegistry $registry,
        protected Renderer $renderer,
        protected Snapshotter $snapshotter,
        protected ConfigRepository $config,
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
            $this->invoker->call([$component, 'mount'], $params);
        } else {
            // No mount(): a parameter matching a public property sets it. That
            // is what makes <livewire:seat-counter :quantity="3" /> work on a
            // component with nothing to do on mount, instead of requiring a
            // mount() whose whole body assigns its own arguments.
            //
            // Through setProperty(), so the same public-only guard that
            // protects a browser update applies to a mount parameter too.
            foreach ($params as $key => $value) {
                if (is_string($key)) {
                    $component->setProperty($key, $value);
                }
            }
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

        // A layout may be either kind. An @extends/@yield layout takes the
        // component's HTML as a named section; a Blade component layout takes
        // it as its slot. Applications routinely have one of each — a
        // component shell for the public site and a section layout for the
        // console — and #[Layout] should not care which it was handed.
        if ($this->isComponentLayout($layout[0])) {
            return $this->renderComponentLayout($layout[0], $html);
        }

        return ($this->engine)()->render('livewire::page', [
            '__layout'  => $layout[0],
            '__section' => $layout[1],
            '__slot'    => $html,
        ]);
    }

    /**
     * Whether a layout name refers to a Blade component rather than an
     * @extends-style template.
     *
     * Decided by where it lives: anything under the components directory is a
     * component, which is the same rule the component tag compiler uses.
     */
    protected function isComponentLayout(string $layout): bool
    {
        return str_starts_with($layout, 'components.');
    }

    /**
     * Render the component layout with the page as its slot.
     */
    protected function renderComponentLayout(string $layout, string $html): string
    {
        $name = substr($layout, strlen('components.'));

        $engine = ($this->engine)();

        // The bound Engine may be the renderer itself or a factory holding
        // one; both shapes are in use.
        $renderer = method_exists($engine, 'getRenderer') ? $engine->getRenderer() : $engine;

        ob_start();

        try {
            $renderer->renderComponent($name, [], $html);
        } catch (\Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }

        return (string) ob_get_clean();
    }

    /** Resolve a component's layout: #[Layout] attribute, else config default. */
    protected function layoutFor(Component $component): ?array
    {
        $attributes = (new ReflectionObject($component))->getAttributes(Layout::class);

        if ($attributes !== []) {
            $layout = $attributes[0]->newInstance();
            return [$layout->layout, $layout->section];
        }

        $default = $this->config->get('livewire.layout');

        return $default ? [$default, $this->config->get('livewire.layout_section', 'content')] : null;
    }
}
