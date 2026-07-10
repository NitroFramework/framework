<?php

namespace Nitro\Htmx\Testing;

use Nitro\Http\Request;
use Nitro\Htmx\HtmxComponent;
use Nitro\Htmx\State\ArrayStateStore;
use Nitro\Htmx\State\StateStore;

/**
 * Drive an HTMX component through its lifecycle from a unit test without
 * the full kernel. Lets you set up state, dispatch actions, and inspect
 * the result — properties, view data, emitted events — in isolation.
 *
 * Typical usage:
 *
 *   $h = ComponentHarness::for(Counter::class);
 *   $h->dispatch('increment');
 *   $h->dispatch('increment');
 *   $this->assertSame(2, $h->property('count'));
 *   $this->assertSame(2, $h->viewData()['count']);
 *
 * For form-bound components, set request input via `with()`:
 *
 *   $h = ComponentHarness::for(ContactForm::class)
 *       ->with(['name' => 'A', 'email' => 'bad'])
 *       ->dispatch('submit');
 *   $this->assertTrue($h->property('errors')->any());
 *   $this->assertFalse($h->wasEmitted('form-submitted'));
 *
 * Persistence is isolated per-harness via an ArrayStateStore so tests
 * never leak state into each other (or into the real session).
 */
class ComponentHarness
{
    private HtmxComponent $component;
    private ArrayStateStore $store;
    private array $input = [];
    private array $props = [];
    private array $headers = ['hx-request' => 'true'];
    private bool $booted = false;

    public function __construct(private string $componentClass)
    {
        if (!class_exists($componentClass) || !is_subclass_of($componentClass, HtmxComponent::class)) {
            throw new \InvalidArgumentException("Not an HtmxComponent: {$componentClass}");
        }
        $this->store = new ArrayStateStore();
    }

    public static function for(string $componentClass): self
    {
        return new self($componentClass);
    }

    /** Set props as if @widget passed them. */
    public function withProps(array $props): self
    {
        $this->props = $props;
        return $this;
    }

    /** Set request input (merged into both GET and POST). */
    public function with(array $input): self
    {
        $this->input = array_merge($this->input, $input);
        return $this;
    }

    /** Add a request header (e.g. for HX-Request control). */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[strtolower($name)] = $value;
        return $this;
    }

    /**
     * Dispatch an action method against the component. The component is
     * booted lazily on the first call. Returns $this for chaining.
     */
    public function dispatch(string $action, array $args = []): self
    {
        $this->withBindings(function () use ($action, $args) {
            if (!$this->booted) {
                $this->component = new ($this->componentClass)();
                $this->component->props = $this->props;
                $this->component->onBoot();
                $this->booted = true;
            }
            // Mirror per-request semantics: a real HTTP request creates a
            // fresh component instance, so renderValue/renderContext start
            // null on every request. The harness reuses one instance across
            // dispatches, so we have to reset explicitly — otherwise the
            // "explicit-call-wins" guard in applyActionRenderAttributes()
            // sees a stale value from the previous dispatch and skips.
            $this->component->renderValue   = null;
            $this->component->renderContext = null;

            $this->component->onBeforeAction($action);
            $result = $this->component->$action(...$args);
            $this->component->onAfterAction($action, $result);

            // Mirror the kernel: when the action neither returned a value
            // nor called render()/value() explicitly, give a method-level
            // #[RenderValue] / #[RenderFragment] attribute a chance to apply.
            if ($result === null) {
                $this->component->applyActionRenderAttributes($action);
            }

            $this->component->persistState();
        });
        return $this;
    }

    /** Inspect a public property on the component. */
    public function property(string $name): mixed
    {
        $this->ensureBooted();
        return $this->component->$name;
    }

    /** Get the full view data (reflected props + computed + with()). */
    public function viewData(): array
    {
        $this->ensureBooted();
        return $this->component->viewData();
    }

    /** Whether the named event was emitted during the dispatch chain. */
    public function wasEmitted(string $event): bool
    {
        $this->ensureBooted();
        return in_array($event, $this->component->pendingEventNames(), true);
    }

    /** Underlying component instance (for advanced assertions). */
    public function instance(): HtmxComponent
    {
        $this->ensureBooted();
        return $this->component;
    }

    /** The harness's isolated state store — handy for inspecting what got persisted. */
    public function store(): ArrayStateStore
    {
        return $this->store;
    }

    private function ensureBooted(): void
    {
        if (!$this->booted) {
            $this->withBindings(function () {
                $this->component = new ($this->componentClass)();
                $this->component->props = $this->props;
                $this->component->onBoot();
                $this->booted = true;
            });
        }
    }

    /**
     * Bind a fake request and an isolated ArrayStateStore into the
     * container for the duration of the callback, then restore whatever
     * was there before. Each harness instance keeps the same store
     * across all dispatches so state behaves persistent-within-test.
     */
    private function withBindings(callable $fn): void
    {
        $container = app();
        $previousRequest = $container->has('request') ? $container->make('request') : null;
        $previousStore   = $container->has(StateStore::class) ? $container->make(StateStore::class) : null;

        $request = new Request(
            method: 'POST',
            path: '/test',
            headers: $this->headers,
            query: $this->input,
            body: $this->input,
            files: [],
            server: [],
        );
        $container->singleton('request', fn() => $request);
        $container->singleton(StateStore::class, fn() => $this->store);

        try {
            $fn();
        } finally {
            if ($previousRequest !== null) {
                $container->singleton('request', fn() => $previousRequest);
            }
            if ($previousStore !== null) {
                $container->singleton(StateStore::class, fn() => $previousStore);
            }
        }
    }
}
