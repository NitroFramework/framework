<?php

namespace Nitro\Livewire;

use Closure;
use Nitro\Livewire\Features\SupportsComputedProperties;
use Nitro\Livewire\Features\SupportsIslands;
use Nitro\Livewire\Features\SupportsLockedProperties;
use Nitro\Livewire\Features\SupportsUrlBindings;
use Nitro\Livewire\Features\SupportsValidation;
use Nitro\Livewire\Hooks\ComponentHooks;
use Nitro\Livewire\Properties\InteractsWithProperties;
use Nitro\Livewire\Support\ErrorBag;
use Nitro\Livewire\Support\Slot;
use Nitro\Livewire\Support\SlotBag;
use Nitro\View\Contracts\ViewEngine;
use ReflectionMethod;
use ReflectionObject;
use RuntimeException;

/**
 * Base class for Livewire components. Public properties are the component's
 * state (persisted across requests as a snapshot); public methods are actions
 * callable from the browser via wire: directives. Subclasses implement render()
 * (or rely on the conventional view) and may define a mount() to receive initial
 * parameters.
 *
 * The class is deliberately THIN. What lives here is what belongs to a component
 * as such — its identity, its view, the channels it talks back to the browser on
 * (events, redirects, regions, slots) — plus a delegating surface for the
 * framework FEATURES. Each feature (validation, #[Url], #[Locked], @island,
 * #[Lazy], #[Computed], uploads) is a {@see \Nitro\Livewire\Hooks\ComponentHook}
 * in Features/, holding its own per-component state; the one-line methods below
 * are the stable API those features are reached through, so an application never
 * has to know a feature was refactored.
 */
abstract class Component
{
    use InteractsWithProperties;

    /** Instance id — assigned by the manager, carried in the snapshot memo. */
    private string $__id = '';

    /** Component name (e.g. 'counter') — assigned by the manager. */
    private string $__name = '';

    /** This component's framework feature hooks, built on first use. */
    private ?ComponentHooks $__hooks = null;

    /** Events dispatched during this request, delivered to the client as effects. */
    private array $__dispatches = [];

    /** A pending redirect (set by redirect()), delivered to the client as an effect. */
    private ?array $__redirect = null;

    /** A region name (set by renderRegion()/#[RenderRegion]) to scope re-render to. */
    private ?string $__region = null;

    /** The inline view name for a single-file component (livewire-sfc::…), if any. */
    private ?string $__inlineView = null;

    /** Named slots passed in by a parent component (name => Slot). */
    private array $__slots = [];

    /**
     * This component's feature hooks. Built lazily and owned by the component
     * itself, so a component constructed directly — in a test, or as a
     * single-file component — has its features working exactly as a
     * manager-made one does.
     */
    public function hooks(): ComponentHooks
    {
        return $this->__hooks ??= new ComponentHooks($this);
    }

    // ─── Rendering ──────────────────────────────────────────────────────────

    /**
     * Render the component to HTML. The default renders the conventional view
     * (livewire.{name}); override to choose a view or pass extra data.
     */
    public function render(): string
    {
        return $this->view($this->__inlineView ?? ('livewire.' . $this->__name));
    }

    /** Set the co-located view of a single-file component (used by the manager). */
    public function setInlineView(string $view): void
    {
        $this->__inlineView = $view;
    }

    /**
     * Render a Blade view through Nitro's view engine, with the component's
     * public properties available as variables.
     */
    protected function view(string $view, array $data = []): string
    {
        $engine = app(ViewEngine::class);
        $data = array_merge($this->all(), [
            'errors' => $this->errors(),
            'slot'   => $this->__slots['default'] ?? new Slot(''),
            'slots'  => new SlotBag($this->__slots),
        ], $data);

        // Render with $this bound to the component so $this->prop and computed
        // properties resolve inside the view.
        if (method_exists($engine, 'renderWithContext')) {
            return $engine->renderWithContext($view, $data, $this);
        }

        return $engine->render($view, $data);
    }

    // ─── Slots ──────────────────────────────────────────────────────────────

    /** Assign the named slots passed in by a parent (name => Slot). */
    public function setSlots(array $slots): void
    {
        $this->__slots = $slots;
    }

    /** The slots as raw HTML strings, for persistence in the snapshot memo. */
    public function slotsToArray(): array
    {
        $out = [];
        foreach ($this->__slots as $name => $slot) {
            $out[$name] = $slot instanceof Slot ? $slot->toHtml() : (string) $slot;
        }

        return $out;
    }

    // ─── Validation (SupportsValidation) ────────────────────────────────────

    /**
     * Validate the component's public state against its rules. On failure the
     * errors are recorded and a ValidationException is thrown; on success the
     * validated subset is returned and the error bag cleared.
     */
    public function validate(?array $rules = null): array
    {
        return $this->hooks()->get(SupportsValidation::class)->validate($rules);
    }

    /** Validate a single field (used for real-time wire:model.live validation). */
    public function validateOnly(string $field, ?array $rules = null): void
    {
        $this->hooks()->get(SupportsValidation::class)->validateOnly($field, $rules);
    }

    /** The current validation errors, exposed to the view as $errors. */
    public function errors(): ErrorBag
    {
        return $this->hooks()->get(SupportsValidation::class)->errors();
    }

    /** Raw error array for snapshot persistence. */
    public function errorsToArray(): array
    {
        return $this->hooks()->get(SupportsValidation::class)->toArray();
    }

    /** Restore errors from a snapshot memo. */
    public function setErrors(array $errors): void
    {
        $this->hooks()->get(SupportsValidation::class)->setErrors($errors);
    }

    // ─── Events ───────────────────────────────────────────────────────────

    /** Dispatch a browser/Livewire event, delivered to the client as an effect. */
    public function dispatch(string $event, mixed ...$params): void
    {
        $this->__dispatches[] = ['event' => $event, 'params' => array_values($params)];
    }

    /** Dispatch an event targeted at a specific component name. */
    public function dispatchTo(string $component, string $event, mixed ...$params): void
    {
        $this->__dispatches[] = ['event' => $event, 'params' => array_values($params), 'to' => $component];
    }

    /** The events dispatched this request. */
    public function dispatchesToArray(): array
    {
        return $this->__dispatches;
    }

    /**
     * The component's event listeners (event => method), discovered from
     * #[On('event')] attributes. Carried in the snapshot memo so the client knows
     * which components to call when an event is dispatched.
     *
     * @return array<string, string>
     */
    public function listeners(): array
    {
        $listeners = [];

        foreach ((new ReflectionObject($this))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(\Nitro\Livewire\Attributes\On::class) as $attribute) {
                $listeners[$attribute->newInstance()->event] = $method->getName();
            }
        }

        return $listeners;
    }

    // ─── Redirects ──────────────────────────────────────────────────────────

    /**
     * Redirect the browser after this request. With $navigate the client swaps
     * the page in-place (SPA-style, like wire:navigate) instead of a hard load.
     */
    public function redirect(string $url, bool $navigate = false): void
    {
        $this->__redirect = ['url' => $url, 'navigate' => $navigate];
    }

    /** The pending redirect for this request, or null. */
    public function redirectToArray(): ?array
    {
        return $this->__redirect;
    }

    // ─── Response shape (region) ────────────────────────────────────────────

    /**
     * Re-render only the named region of the component (a wire:region block).
     * The explicit counterpart of #[RenderRegion].
     */
    public function renderRegion(string $region): void
    {
        $this->__region = $region;
    }

    /** The region this response is scoped to, or null. */
    public function pullRegion(): ?string
    {
        return $this->__region;
    }

    // ─── Islands (SupportsIslands) ──────────────────────────────────────────

    /**
     * Set the island render mode before a render pass. On the initial render all
     * islands render (or their placeholder if lazy/defer); on a re-render only the
     * targeted island renders and the rest freeze (their bodies are not executed).
     */
    public function beginIslandRender(bool $renderAll, ?string $target = null): void
    {
        $this->hooks()->get(SupportsIslands::class)->begin($renderAll, $target);
    }

    /**
     * Render an @island block. Called from compiled views with the island's
     * deferred body (and optional placeholder) closures.
     */
    public function island(string $name, array $options, array $scope, Closure $body, ?Closure $placeholder = null): string
    {
        return $this->hooks()->get(SupportsIslands::class)->island($name, $options, $scope, $body, $placeholder);
    }

    // ─── URL query-string bindings (SupportsUrlBindings) ────────────────────

    /**
     * The component's #[Url] property bindings — property => { as, history,
     * default }.
     *
     * @return array<string, array{as: string, history: bool, default: mixed}>
     */
    public function urlBindings(): array
    {
        return $this->hooks()->get(SupportsUrlBindings::class)->bindings();
    }

    /** Seed #[Url] properties from the current request's query string (on mount). */
    public function initializeUrlBindings(): void
    {
        $this->hooks()->get(SupportsUrlBindings::class)->initialize();
    }

    // ─── Locked properties (SupportsLockedProperties) ───────────────────────

    /**
     * Whether the root property behind an update key is marked #[Locked] — i.e.
     * the browser must not be allowed to change it.
     */
    public function isPropertyLocked(string $key): bool
    {
        return SupportsLockedProperties::isLocked($this, $key);
    }

    // ─── Magic (SupportsComputedProperties) ─────────────────────────────────

    /** Resolve a computed property (a #[Computed] method), memoized per request. */
    public function __get(string $name): mixed
    {
        $computed = $this->hooks()->get(SupportsComputedProperties::class);

        if ($computed->isResolved($name) || $computed->has($name)) {
            return $computed->get($name);
        }

        throw new RuntimeException("Property [{$name}] does not exist on component [{$this->__name}].");
    }

    /**
     * Forward Blade directive helper calls (addLoop, startSection, yieldContent,
     * …) to the view engine when a component view is rendered with $this bound to
     * the component. Real component actions are public methods and never reach here.
     */
    public function __call(string $method, array $arguments): mixed
    {
        return app(ViewEngine::class)->{$method}(...$arguments);
    }

    // ─── Identity ───────────────────────────────────────────────────────────

    /** Assign the manager-owned identity. */
    public function setContext(string $id, string $name): void
    {
        $this->__id = $id;
        $this->__name = $name;
    }

    public function getId(): string
    {
        return $this->__id;
    }

    public function getName(): string
    {
        return $this->__name;
    }
}
