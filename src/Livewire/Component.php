<?php

namespace Nitro\Livewire;

use Nitro\Support\Arr;
use Nitro\Livewire\Attributes\On;
use Nitro\Livewire\Synthesizers\SynthManager;
use Nitro\Livewire\Attributes\Locked;
use Nitro\Livewire\Attributes\Computed;
use Nitro\Livewire\Attributes\Url;
use Nitro\Validation\ValidationException;
use Nitro\Validation\Validator;
use Nitro\View\Contracts\ViewEngine;
use ReflectionMethod;
use ReflectionObject;
use ReflectionProperty;
use RuntimeException;

/**
 * Base class for Livewire components. Public properties are the component's
 * state (persisted across requests as a snapshot); public methods are actions
 * callable from the browser via wire: directives. Subclasses implement render()
 * (or rely on the conventional view) and may define a mount() to receive initial
 * parameters.
 */
abstract class Component
{
    /** Instance id — assigned by the manager, carried in the snapshot memo. */
    private string $__id = '';

    /** Component name (e.g. 'counter') — assigned by the manager. */
    private string $__name = '';

    /** Per-request memo of resolved computed properties. */
    private array $__computed = [];

    /** Validation errors, keyed by field => [messages]; persisted in the snapshot memo. */
    private array $__errors = [];

    /** Events dispatched during this request, delivered to the client as effects. */
    private array $__dispatches = [];

    /** A pending redirect (set by redirect()), delivered to the client as an effect. */
    private ?array $__redirect = null;

    /** A region name (set by renderRegion()/#[RenderRegion]) to scope re-render to. */
    private ?string $__region = null;

    /** Island render mode for this pass: render all (initial) vs re-render one target. */
    private bool $__islandRenderAll = false;
    private ?string $__islandTarget = null;

    /** The inline view name for a single-file component (livewire-sfc::…), if any. */
    private ?string $__inlineView = null;

    /** Named slots passed in by a parent component (name => Slot). */
    private array $__slots = [];

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

    // ─── Validation ───────────────────────────────────────────────────────

    /**
     * Validate the component's public state against its rules (argument, then a
     * rules() method, then a $rules property). On failure the errors are recorded
     * and a ValidationException is thrown; on success the validated subset is
     * returned and the error bag cleared.
     */
    public function validate(?array $rules = null): array
    {
        $validator = new Validator($this->all(), $rules ?? $this->resolveRules());

        if ($validator->fails()) {
            $this->__errors = $validator->errors()->all();
            throw new ValidationException($validator->errors());
        }

        $this->__errors = [];

        return $validator->validated();
    }

    /** Validate a single field (used for real-time wire:model.live validation). */
    public function validateOnly(string $field, ?array $rules = null): void
    {
        $rules = $rules ?? $this->resolveRules();
        $subset = isset($rules[$field]) ? [$field => $rules[$field]] : [];

        $validator = new Validator($this->all(), $subset);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $f => $messages) {
                $this->__errors[$f] = $messages;
            }
            throw new ValidationException($validator->errors());
        }

        unset($this->__errors[$field]);
    }

    /** Rules from a rules() method or a $rules property. */
    protected function resolveRules(): array
    {
        if (method_exists($this, 'rules')) {
            return $this->rules();
        }

        if (property_exists($this, 'rules')) {
            return (array) $this->rules;
        }

        return [];
    }

    /** The current validation errors, exposed to the view as $errors. */
    public function errors(): ErrorBag
    {
        return new ErrorBag($this->__errors);
    }

    /** Raw error array for snapshot persistence. */
    public function errorsToArray(): array
    {
        return $this->__errors;
    }

    /** Restore errors from a snapshot memo. */
    public function setErrors(array $errors): void
    {
        $this->__errors = $errors;
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

    // ─── Islands ────────────────────────────────────────────────────────────

    /**
     * Set the island render mode before a render pass. On the initial render all
     * islands render (or their placeholder if lazy/defer); on a re-render only the
     * targeted island renders and the rest freeze (their bodies are not executed).
     */
    public function beginIslandRender(bool $renderAll, ?string $target = null): void
    {
        $this->__islandRenderAll = $renderAll;
        $this->__islandTarget = $target;
    }

    /**
     * Render an @island block. Called from compiled views with the island's
     * deferred body (and optional placeholder) closures — the closure is only
     * invoked when this island should actually render.
     */
    public function island(string $name, array $options, array $scope, \Closure $body, ?\Closure $placeholder = null): string
    {
        $mode = $this->islandMode($name, $options);
        $attr = 'wire:island="' . htmlspecialchars($name, ENT_QUOTES) . '"';

        if ($mode === 'render') {
            $scope = array_merge($scope, (array) ($options['with'] ?? []));

            return '<div ' . $attr . '>' . $this->runIsland($body, $scope) . '</div>';
        }

        if ($mode === 'placeholder') {
            $scope = array_merge($scope, (array) ($options['with'] ?? []));
            $html = $placeholder !== null ? $this->runIsland($placeholder, $scope) : $this->islandPlaceholder();
            $flag = ($options['lazy'] ?? false) ? 'wire:island-lazy' : 'wire:island-defer';

            return '<div ' . $attr . ' ' . $flag . '>' . $html . '</div>';
        }

        // Frozen: emit a keep marker; the client leaves the existing island DOM.
        return '<div ' . $attr . ' wire:island-keep></div>';
    }

    /** Decide whether an island renders, shows a placeholder, or freezes this pass. */
    protected function islandMode(string $name, array $options): string
    {
        if ($this->__islandRenderAll) {
            return (($options['lazy'] ?? false) || ($options['defer'] ?? false)) ? 'placeholder' : 'render';
        }

        if ($options['always'] ?? false) {
            return 'render';
        }

        return $this->__islandTarget === $name ? 'render' : 'skip';
    }

    /** Run an island body/placeholder closure with $this = component and its scope. */
    protected function runIsland(\Closure $closure, array $scope): string
    {
        return (string) $closure->call($this, $scope);
    }

    /** The fallback placeholder for a lazy/defer island with no @placeholder block. */
    protected function islandPlaceholder(): string
    {
        if (method_exists($this, 'placeholder')) {
            return $this->placeholder();
        }

        return '<div class="animate-pulse rounded bg-slate-100 p-4 dark:bg-slate-800">&nbsp;</div>';
    }

    // ─── URL query-string bindings ──────────────────────────────────────────

    /**
     * The component's #[Url] property bindings — property => { as, history,
     * default } — carried in the snapshot memo so the client keeps matching
     * query-string keys in sync as those properties change.
     *
     * @return array<string, array{as: string, history: bool, default: mixed}>
     */
    public function urlBindings(): array
    {
        $bindings = [];

        foreach ((new ReflectionObject($this))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $attributes = $property->getAttributes(Url::class);
            if ($attributes === []) {
                continue;
            }

            $url = $attributes[0]->newInstance();
            $name = $property->getName();
            $bindings[$name] = [
                'as'      => $url->as ?? $name,
                'history' => $url->history,
                'default' => $property->hasDefaultValue() ? $property->getDefaultValue() : null,
            ];
        }

        return $bindings;
    }

    /** Seed #[Url] properties from the current request's query string (on mount). */
    public function initializeUrlBindings(): void
    {
        $bindings = $this->urlBindings();
        if ($bindings === []) {
            return;
        }

        $query = (array) request()->query();

        foreach ($bindings as $name => $binding) {
            if (array_key_exists($binding['as'], $query)) {
                $this->setProperty($name, $query[$binding['as']]);
            }
        }
    }

    /** Resolve a computed property (a #[Computed] method), memoized per request. */
    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->__computed)) {
            return $this->__computed[$name];
        }

        if ($this->isComputed($name)) {
            return $this->__computed[$name] = $this->{$name}();
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

    /** Whether the given method is a #[Computed] property. */
    private function isComputed(string $name): bool
    {
        if (! method_exists($this, $name)) {
            return false;
        }

        return (new ReflectionMethod($this, $name))->getAttributes(Computed::class) !== [];
    }

    /**
     * The component's public property values — its serializable state.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $state = [];

        foreach ((new ReflectionObject($this))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $state[$property->getName()] = $property->getValue($this);
        }

        return $state;
    }

    /** Assign a public property value (used by hydration and update commits). */
    public function setProperty(string $name, mixed $value): void
    {
        // A wire:model file input sends an upload token (or array of them);
        // promote it to a TemporaryUploadedFile before it lands on the property.
        if (TemporaryUploadedFile::isUploadToken($value)) {
            $value = TemporaryUploadedFile::fromValue($value);
        }

        // Nested binding: wire:model="form.email" writes into an array property.
        if (str_contains($name, '.')) {
            [$root, $path] = explode('.', $name, 2);
            if ($this->isPublicProperty($root) && is_array($this->{$root})) {
                $array = $this->{$root};
                Arr::set($array, $path, $value);
                $this->{$root} = $array;
            }
            return;
        }

        if ($this->isPublicProperty($name)) {
            $this->{$name} = $this->coerce($name, $value);
        }
    }

    /**
     * Whether $name is a PUBLIC property — the only kind the browser may write.
     *
     * A forged `updates` entry / wire:model must never reach protected or private
     * state (server-only invariants, cached flags, service handles). This matches
     * both the properties Nitro exposes in the snapshot (public only) and
     * Livewire's own update guard (getPublicPropertiesDefinedOnSubclass).
     */
    private function isPublicProperty(string $name): bool
    {
        if (! property_exists($this, $name)) {
            return false;
        }

        return (new ReflectionProperty($this, $name))->isPublic();
    }

    /** Coerce an incoming wire:model value to the property's declared type. */
    private function coerce(string $name, mixed $value): mixed
    {
        $type = (new ReflectionProperty($this, $name))->getType();

        if (! $type instanceof \ReflectionNamedType) {
            return $value;
        }

        $typeName = $type->getName();

        // Numeric scalars and every class type (enum, DateTime, …) go through a
        // TypeSynth — a wire:model input arrives as a string, so an emptied field
        // sends "" that must become null (not 0, not a TypeError). The other
        // builtins (bool/string/array) never hit that path, so a scalar property
        // never triggers the enum_exists()/is_a() class lookups.
        if ($typeName === 'int' || $typeName === 'float' || ! $type->isBuiltin()) {
            return $this->coerceViaSynth($type, $value);
        }

        if ($value === null) {
            return $value;
        }

        return match ($typeName) {
            'bool'   => is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'string' => (string) $value,
            'array'  => (array) $value,
            default  => $value,
        };
    }

    /** Apply the matching TypeSynth, then keep the result assignable to the type. */
    private function coerceViaSynth(\ReflectionNamedType $type, mixed $value): mixed
    {
        $typeName = $type->getName();
        $synth = \Nitro\Livewire\Synthesizers\SynthManager::typeSynthFor($typeName);

        // Unknown class type with no synth (e.g. a Model set in mount()) — leave
        // the incoming value untouched rather than guess at a coercion.
        if ($synth === null) {
            return $value;
        }

        $coerced = $synth->hydrateFromType($typeName, $value);

        // A synth may hand back a value it couldn't coerce (FloatSynth returns a
        // raw non-numeric string); a typed property can't hold that, so treat it
        // as "no value" and let validation report it instead of a TypeError.
        if ($coerced !== null && ! $this->valueFitsType($typeName, $coerced)) {
            $coerced = null;
        }

        // A non-nullable field can't hold null (an emptied input): numerics fall
        // back to zero; other typed props should be declared nullable to accept
        // an empty value.
        if ($coerced === null && ! $type->allowsNull()) {
            return match ($typeName) {
                'int'   => 0,
                'float' => 0.0,
                default => $value,
            };
        }

        return $coerced;
    }

    /** Whether a coerced value is assignable to the declared scalar/class type. */
    private function valueFitsType(string $typeName, mixed $value): bool
    {
        return match ($typeName) {
            'int'   => is_int($value),
            'float' => is_int($value) || is_float($value),
            default => $value instanceof $typeName,
        };
    }

    /** @var array<class-string, array<string, bool>> Memoized #[Locked] flags per class. */
    private static array $lockedCache = [];

    /**
     * Whether the root property behind an update key is marked #[Locked] — i.e.
     * the browser must not be allowed to change it. Nested keys (form.x) resolve
     * to their root property.
     */
    public function isPropertyLocked(string $key): bool
    {
        $root = str_contains($key, '.') ? explode('.', $key, 2)[0] : $key;

        if (! isset(self::$lockedCache[static::class][$root])) {
            $locked = property_exists($this, $root)
                && (new ReflectionProperty($this, $root))
                    ->getAttributes(\Nitro\Livewire\Attributes\Locked::class) !== [];

            self::$lockedCache[static::class][$root] = $locked;
        }

        return self::$lockedCache[static::class][$root];
    }

    /** @var array<class-string, true> Classes already validated as fully typed. */
    private static array $typedChecked = [];

    /**
     * Enforce Nitro's typed-property design: every public component property must
     * declare a type, so incoming wire:model values coerce deterministically
     * (see coerce()). An untyped property is a bug and fails loudly. Validated
     * once per component class.
     */
    public function assertPropertiesAreTyped(): void
    {
        if (isset(self::$typedChecked[static::class])) {
            return;
        }

        foreach ((new ReflectionObject($this))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            if (! $property->hasType()) {
                throw new \RuntimeException(sprintf(
                    'Livewire component [%s]: public property [$%s] has no type. Nitro requires '
                    . 'typed public properties (e.g. `public ?string $%s = null;`) so wire:model '
                    . 'values coerce correctly instead of silently mis-casting.',
                    static::class,
                    $property->getName(),
                    $property->getName(),
                ));
            }
        }

        self::$typedChecked[static::class] = true;
    }

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
