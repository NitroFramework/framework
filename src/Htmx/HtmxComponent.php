<?php

namespace Nitro\Htmx;

use Nitro\Htmx\Attributes\Computed;
use Nitro\Htmx\Attributes\Modelable;
use Nitro\Htmx\Attributes\RenderFragment;
use Nitro\Htmx\Attributes\RenderValue;
use Nitro\Htmx\Concerns\HasAutoState;
use Nitro\Htmx\Concerns\HasEvents;
use Nitro\Htmx\Concerns\HasRendering;
use Nitro\Htmx\Concerns\HasValidation;
use Nitro\Htmx\RenderContext;

class HtmxComponent
{
    use HasEvents, HasValidation, HasRendering, HasAutoState;

    public ?string $renderView = null;
    public array   $renderData = [];

    /** When set, only these @fragment blocks are rendered (null = render full view) */
    public ?array $renderFragments = null;

    /** The render context set by render() — read by the kernel */
    public ?RenderContext $renderContext = null;

    /** Props passed during embedding */
    public array $props = [];
    public mixed $renderValue = null;

    /**
     * Embed-site render overrides. These are stamped by HtmxComponentRenderer
     * when @widget passes value:/full:, and round-trip on every subsequent
     * action via the envelope's hx-vals (_hxvalue / _hxfull). When set, they
     * win over any method-level #[RenderValue]/#[RenderFragment] attribute —
     * the call-site's intent for this specific instance beats the component
     * author's per-method default.
     *
     * Mutually exclusive at the embed site:
     *   value:           $renderValueProperty = 'count'
     *   fragments: [..]: $renderFragments     = [...]   (existing channel)
     *   full: true:      $forceFullRender     = true
     */
    public ?string $renderValueProperty = null;
    public bool    $forceFullRender     = false;

    /**
     * Default view name used by auto-render when an action doesn't call
     * render() explicitly. When null, the view is inferred from the class
     * name via the configured view_path_prefix (e.g. Counter →
     * "components.htmx.counter").
     */
    protected ?string $view = null;

    /**
     * Layout to wrap the rendered view with on full-page requests (i.e.
     * the index action hit via a browser URL, not an HTMX action).
     * Leave null for components that are only embedded as @widget.
     */
    protected ?string $layout = null;

    /**
     * Auto-poll interval in milliseconds. When set, the wrapper carries
     * hx-poll + hx-poll-action so descendants/the wrapper itself fire
     * the named action on the schedule. Use null to disable polling.
     */
    protected ?int $pollInterval = null;

    /** Action invoked on each poll tick. */
    protected ?string $pollAction = null;

    /**
     * When true (the default), actions that don't explicitly render anything
     * get an auto-rendered response built from $view + public properties.
     * When false, missing-render falls back to the legacy empty response.
     */
    protected bool $autoRender = true;

    /**
     * When true (the default), public properties round-trip through the
     * session so the component can be written with $this->prop++ semantics.
     * Override per-component only when you need to opt OUT.
     */
    protected bool $persistState = true;

    /**
     * Property names that are hydrated from request input. Alternative
     * to the #[Modelable] attribute — pick whichever style you prefer:
     *
     *   #[Modelable] public string $email = '';   // per-property
     *
     *   // — or —
     *
     *   protected array $modelable = ['email', 'name'];  // class-level list
     *
     * Properties NOT covered by either form are server-only — auto-persisted
     * but ignored when the client sends a value with the same name.
     */
    protected array $modelable = [];

    /** Framework-owned public properties that are never reflected into view
     *  data, never persisted, and never hydrated from request input. */
    public static function reservedProperties(): array
    {
        return [
            'renderView',
            'renderData',
            'renderFragments',
            'renderContext',
            'props',
            'renderValue',
            'instanceId',
            'renderValueProperty',
            'forceFullRender',
        ];
    }

    /**
     * Per-class reflection metadata, computed once and cached for the worker's
     * lifetime. A component's public properties, #[Modelable] flags, #[Computed]
     * methods and short name are fixed at definition time — so reflecting them
     * on every render (and ~8 ReflectionClass builds per render, multiplied by
     * the 9 widgets a page like the dashboard embeds) is pure waste. Keyed by
     * class name so each subclass gets its own entry; shared across every
     * instance of that class on the page (e.g. Notes ×3, Counter ×N).
     *
     * @var array<class-string, array{shortName:string, publicProps:string[], modelableProps:string[], computedMethods:string[]}>
     */
    private static array $reflectionCache = [];

    /**
     * Build (or fetch) this component's cached reflection metadata. One
     * ReflectionClass pass per class, then plain array reads forever after.
     * Reserved-property filtering stays at the call site (cheap in_array over
     * a handful of names) so a subclass overriding reservedProperties() still
     * works without invalidating the cache.
     *
     * @return array{shortName:string, publicProps:string[], modelableProps:string[], computedMethods:string[]}
     */
    protected function reflectionMeta(): array
    {
        $class = static::class;
        if (isset(self::$reflectionCache[$class])) {
            return self::$reflectionCache[$class];
        }

        $ref = new \ReflectionClass($this);

        $publicProps    = [];
        $modelableProps = [];
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            $name = $prop->getName();
            $publicProps[] = $name;
            if (count($prop->getAttributes(Modelable::class)) > 0) {
                $modelableProps[] = $name;
            }
        }

        $computedMethods = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
                continue;
            }
            if (count($method->getAttributes(Computed::class)) === 0) {
                continue;
            }
            $computedMethods[] = $method->getName();
        }

        return self::$reflectionCache[$class] = [
            'shortName'       => $ref->getShortName(),
            'publicProps'     => $publicProps,
            'modelableProps'  => $modelableProps,
            'computedMethods' => $computedMethods,
        ];
    }

    // ── Lifecycle hooks ──

    public function onBoot(): void
    {
        $this->resolveInstanceId();
        $this->loadState();
        $this->hydrateProperties();
        $this->resolveRenderOverrides();
        $this->resolveFragmentScope();

        // Initial mount: state was empty — fire onMount() once so the
        // component can seed defaults. Subsequent requests reuse the
        // persisted snapshot and skip this.
        if ($this->justMounted) {
            $this->onMount();
        }
    }

    /**
     * Read the fragment-scope param from the request and apply it to
     * $renderFragments so default-render stays inside the same fragments
     * the widget was originally mounted with.
     */
    private function resolveFragmentScope(): void
    {
        // forceFullRender beats fragment scope — embed-site "full" wins
        // even if a stale _hxfrags ride-along is still in the request.
        if ($this->forceFullRender) {
            return;
        }

        $param = config('htmx.fragments_param', '_hxfrags');
        $request = app('request');
        $raw = $request->get($param) ?? $request->post($param);

        if (!is_string($raw) || $raw === '') {
            return;
        }

        $fragments = array_values(array_filter(array_map('trim', explode(',', $raw))));
        if (!empty($fragments)) {
            $this->renderFragments = $fragments;
        }
    }

    /**
     * Read the embed-site render-mode overrides from the request and
     * stamp them onto $this. Mirrors resolveFragmentScope() but for the
     * value: / full: channels.
     *
     * Round-tripped via the envelope's hx-vals on every action, so the
     * embed-site choice survives across the entire conversation, not
     * just the initial mount.
     */
    private function resolveRenderOverrides(): void
    {
        $request = app('request');

        $fullParam = config('htmx.full_render_param', '_hxfull');
        $fullRaw   = $request->get($fullParam) ?? $request->post($fullParam);
        if ($fullRaw !== null && $fullRaw !== '' && $fullRaw !== '0' && $fullRaw !== false) {
            $this->forceFullRender = true;
            $this->renderFragments = null; // full beats fragment scope
        }

        $valueParam = config('htmx.value_property_param', '_hxvalue');
        $valueRaw   = $request->get($valueParam) ?? $request->post($valueParam);
        if (is_string($valueRaw) && $valueRaw !== '') {
            $this->renderValueProperty = $valueRaw;
        }
    }
    public function onMount(): void {}
    public function onBeforeAction(string $action): void {}
    public function onAfterAction(string $action, mixed $result): void {}

    /**
     * Authorize an action invocation. Return false to deny — the kernel
     * will respond 403 without ever calling the action method. Default
     * is "everyone can do everything"; override to enforce per-action
     * permissions:
     *
     *   protected function authorize(string $action): bool {
     *       return match ($action) {
     *           'delete', 'reset' => auth()->user()?->is_admin,
     *           default           => true,
     *       };
     *   }
     */
    protected function authorize(string $action): bool
    {
        return true;
    }

    /** Public bridge so the kernel can call the protected hook. */
    public function isAuthorizedFor(string $action): bool
    {
        return $this->authorize($action);
    }

    /**
     * Full-page entry action. The router hits this when someone visits the
     * component's page URL directly. The default does nothing — auto-render
     * kicks in afterwards, picks the convention view, and (because $isPage
     * is true) wraps it in the layout declared on the component class.
     *
     * Override only when a page-load needs custom setup beyond declaring
     * a layout and public properties.
     */
    public function index(): void
    {
    }

    public function resolveLayout(): ?string
    {
        return $this->layout;
    }

    public function resolvePollInterval(): ?int
    {
        return $this->pollInterval;
    }

    public function resolvePollAction(): ?string
    {
        return $this->pollAction;
    }

    /**
     * Override to scope this component's state to a stable key instead of
     * a per-instance random ID. All widgets returning the same key share
     * state — useful when the "instance" is really a logical bucket like
     * a user, a tenant, or a category.
     *
     *   public function instanceKey(): ?string {
     *       return $this->props['category'] ?? 'general';
     *   }
     *
     * Return null (the default) to use a per-instance random ID.
     */
    public function instanceKey(): ?string
    {
        return null;
    }

    // ── Request access ──

    protected function get(string $key, mixed $default = null): mixed
    {
        $request = app('request');
        return $request->get($key) ?? $request->post($key) ?? $default;
    }

    /**
     * Hydrate request input into properties that opted in via
     * #[Modelable] or by being listed in $modelable. Without an opt-in,
     * public properties are server-only — auto-persisted but not
     * writable by the client.
     */
    private function hydrateProperties(): void
    {
        $request = app('request');
        $reserved = self::reservedProperties();
        $meta = $this->reflectionMeta();
        // Union of #[Modelable]-attributed props and the $modelable allowlist.
        $modelable = array_merge($meta['modelableProps'], $this->modelable ?? []);

        foreach ($meta['publicProps'] as $name) {
            if (in_array($name, $reserved, true)) {
                continue;
            }

            if (!in_array($name, $modelable, true)) {
                continue;
            }

            $value = $request->get($name) ?? $request->post($name);

            if ($value === null) {
                continue;
            }

            $current = $this->$name;
            $type = gettype($current);

            $cast = match ($type) {
                'integer' => (int) $value,
                'double'  => (float) $value,
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'array'   => (array) $value,
                default   => $value,
            };

            // Livewire-style property hooks: react to a client-driven change to
            // THIS property. onUpdating fires before the value lands, onUpdated
            // after — each in a generic form (onUpdating($name,$value)) and a
            // targeted form (onUpdatingName($value)). Opt-in: nothing fires
            // unless the component defines the method.
            $this->fireUpdateHook('onUpdating', $name, $cast);
            $this->$name = $cast;
            $this->fireUpdateHook('onUpdated', $name, $cast);
        }
    }

    /**
     * Per-class cache of lifecycle-hook method existence, keyed by
     * "Class::method". Hook methods are fixed at definition time, so a single
     * method_exists() per (class, method) keeps the hot path allocation-free —
     * the same optimization Livewire's SupportLifecycleHooks uses.
     *
     * @var array<string, bool>
     */
    private static array $hookExistsCache = [];

    private function hasHook(string $method): bool
    {
        return self::$hookExistsCache[static::class . '::' . $method]
            ??= method_exists($this, $method);
    }

    /**
     * Fire a property-update hook in both shapes when defined:
     *   onUpdating / onUpdated($name, $value)        — generic, every property
     *   onUpdatingName / onUpdatedName($value)        — targeted (camelCase prop)
     *
     * These are framework-invoked only; ComponentResolver blocks the
     * onUpdating / onUpdated prefixes from being called as client actions.
     */
    private function fireUpdateHook(string $prefix, string $name, mixed $value): void
    {
        if ($this->hasHook($prefix)) {
            $this->{$prefix}($name, $value);
        }

        $targeted = $prefix . ucfirst($name);
        if ($this->hasHook($targeted)) {
            $this->{$targeted}($value);
        }
    }

    /**
     * Resolve the default view name used by auto-render.
     *
     * Cascade (first match wins):
     *
     *   1. view() method  — Livewire-style declarative binding. Optional.
     *                       Implement this when the view depends on component
     *                       state (e.g. role-based template selection) or
     *                       when you want the binding to be explicit in the
     *                       class rather than hidden in convention:
     *
     *                           public function view(): string {
     *                               return $this->isAdmin
     *                                   ? 'admin.dashboard'
     *                                   : 'user.dashboard';
     *                           }
     *
     *   2. $view property — Static override. Set when the view name is fixed
     *                       but doesn't match the convention.
     *
     *   3. Convention     — class name → kebab-cased view path:
     *                           Counter      → components.htmx.counter
     *                           StudentTable → components.htmx.student-table
     *
     * Data is unchanged regardless of which branch fires — public props
     * reflect into viewData(), with() / #[Computed] layer on top. The
     * view() method declares WHICH view; it doesn't render it.
     */
    public function resolveDefaultView(): string
    {
        if (method_exists($this, 'view')) {
            return (string) $this->view();
        }

        if ($this->view !== null) {
            return $this->view;
        }

        $shortName = $this->reflectionMeta()['shortName'];
        $kebab = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $shortName));
        return config('htmx.view_path_prefix', 'components.htmx.') . $kebab;
    }

    /**
     * Reflect all non-reserved public properties as view data. Called by
     * the kernel before merging with() on top.
     */
    public function reflectPublicProperties(): array
    {
        $reserved = self::reservedProperties();
        $data = [];
        foreach ($this->reflectionMeta()['publicProps'] as $name) {
            if (in_array($name, $reserved, true)) continue;
            $data[$name] = $this->$name;
        }
        return $data;
    }

    /**
     * Extra view data merged on top of the reflected public properties.
     * Override per-component when the view needs derived values that
     * aren't simple public properties.
     *
     *   public function with(): array {
     *       return ['content' => $this->getTabContent($this->activeTab)];
     *   }
     */
    public function with(): array
    {
        return [];
    }

    /**
     * Invoke every public no-arg method marked with #[Computed] and
     * return their results keyed by method name.
     */
    public function reflectComputedProperties(): array
    {
        $data = [];
        foreach ($this->reflectionMeta()['computedMethods'] as $name) {
            $data[$name] = $this->{$name}();
        }
        return $data;
    }

    /**
     * Final view data:
     *   1. reflected public properties (lowest priority)
     *   2. #[Computed] method results (override props with same name)
     *   3. with() (highest priority — author's explicit say)
     *
     * The 'slots' prop is also surfaced as a top-level $slots variable
     * so widget views can read $slots['title'] / $slots['body'] / etc.
     * without poking through $this->props. Pre-render Blade to a string
     * if you need rich content:
     *
     *   @php $body = view('partials.modal-body', $data)->render(); @endphp
     *   @widget('Modal', ['slots' => ['title' => 'Confirm', 'body' => $body]])
     */
    public function viewData(): array
    {
        $data = array_merge(
            $this->reflectPublicProperties(),
            $this->reflectComputedProperties(),
            $this->with(),
        );

        if (isset($this->props['slots']) && is_array($this->props['slots'])) {
            $data['slots'] = $this->props['slots'];
        }

        // Make framework instance metadata available to views so multi-instance
        // markup can scope its own hx-target selectors and adapt to the active
        // render mode without forcing every component to implement with().
        //
        //   hxId                 — CSS-safe per-instance token (raw of instanceId,
        //                          dot-stripped) for unique IDs and selectors.
        //   renderValueProperty  — the property name when value: override is active,
        //                          else null. Views check it to switch between
        //                          envelope-replacement and innerHTML targeting.
        //   forceFullRender      — true when full: override is active.
        if (!array_key_exists('hxId', $data)) {
            $data['hxId'] = $this->instanceId !== null
                ? explode('.', $this->instanceId)[0]
                : null;
        }
        if (!array_key_exists('renderValueProperty', $data)) {
            $data['renderValueProperty'] = $this->renderValueProperty;
        }
        if (!array_key_exists('forceFullRender', $data)) {
            $data['forceFullRender'] = $this->forceFullRender;
        }
        if (!array_key_exists('renderFragments', $data)) {
            $data['renderFragments'] = $this->renderFragments;
        }

        return $data;
    }

    public function shouldAutoRender(): bool
    {
        return $this->autoRender && config('htmx.auto_render', true);
    }

    /**
     * Apply the render strategy declared by a method-level attribute
     * (#[RenderValue('prop')] or #[RenderFragment('name')]) on the action
     * that just ran. The attribute is the fallback when the action body
     * didn't speak up — an explicit $this->render() / $this->value()
     * inside the action wins, as does $this->skipRender().
     *
     * Returns true when the attribute applied a render strategy, false
     * when there was no attribute or it was skipped.
     */
    public function applyActionRenderAttributes(string $action): bool
    {
        if ($this->renderContext !== null || $this->renderValue !== null) {
            return false;
        }
        if ($this->shouldSkipRender()) {
            return false;
        }

        // ── Embed-site shape overrides win over per-method PHP attributes ──
        //
        // The call site speaks for THIS instance; the attribute speaks for
        // every invocation. Per-instance shape intent beats the attribute's
        // shape default.
        //
        // value:     declares a different RESPONSE SHAPE (scalar) than the attr —
        //            wins, sets renderValue from the named property.
        // full:true: declares a different RESPONSE SHAPE (full view) than the attr —
        //            wins; bypass attrs so auto-render fallback fires.
        //
        // fragments: is NOT a shape override — it's a scope for the auto-render
        // fallback (initial mount + actions that have no attribute). When the
        // method has #[RenderValue] / #[RenderFragment], the attribute's
        // explicit shape choice wins, and fragments: is ignored for that
        // action. Otherwise auto-render fallback honors it.
        if ($this->renderValueProperty !== null) {
            $property = $this->renderValueProperty;
            if (!property_exists($this, $property)) {
                throw new \RuntimeException(
                    "Embed-site value:'{$property}' on " . static::class . " — "
                    . "no such public property on the component."
                );
            }
            $this->renderValue = $this->{$property};
            return true;
        }
        if ($this->forceFullRender) {
            return false;
        }

        try {
            $method = new \ReflectionMethod($this, $action);
        } catch (\ReflectionException) {
            return false;
        }

        $valueAttrs = $method->getAttributes(RenderValue::class);
        if (!empty($valueAttrs)) {
            $property = $valueAttrs[0]->newInstance()->property;
            if (!property_exists($this, $property)) {
                throw new \RuntimeException(
                    "#[RenderValue('{$property}')] on " . static::class . "::{$action}() — "
                    . "no such public property on the component."
                );
            }
            $this->renderValue = $this->{$property};
            return true;
        }

        $fragmentAttrs = $method->getAttributes(RenderFragment::class);
        if (!empty($fragmentAttrs)) {
            $fragment = $fragmentAttrs[0]->newInstance()->name;
            $view = $this->resolveDefaultView();
            $data = $this->viewData();
            $ctx = new RenderContext($view, $data);
            $ctx->withFragments([$fragment]);
            $this->renderContext = $ctx;
            $this->renderView    = $view;
            $this->renderData    = $data;
            return true;
        }

        return false;
    }

    /**
     * Single source of truth for the default-render decision. Both the
     * kernel (handling actions) and the embedding renderer (handling
     * @widget) call this — extracting it here keeps the two callers
     * from drifting on subtle rules (skipRender, fragment scope, layout).
     *
     * Returns true when a render context was set, false otherwise.
     */
    public function applyAutoRenderFallback(bool $isPage = false): bool
    {
        if ($this->renderContext !== null || $this->renderValue !== null) {
            return false;
        }
        if ($this->shouldSkipRender() || !$this->shouldAutoRender()) {
            return false;
        }

        $view = $this->resolveDefaultView();
        $ctx  = new RenderContext($view, $this->viewData());

        if ($isPage && ($layout = $this->resolveLayout()) !== null) {
            $ctx->withLayout($layout);
        }
        if ($this->renderFragments !== null) {
            $ctx->withFragments($this->renderFragments);
        }

        $this->renderContext = $ctx;
        $this->renderView    = $view;
        $this->renderData    = $ctx->data;
        return true;
    }

    /**
     * Wrap rendered HTML in the framework's instance-ID envelope. No-op
     * when the component doesn't persist state. Single source of truth
     * for the wrapper markup — used by HtmxDispatcher for action responses,
     * by HtmxComponentRenderer for @widget embeds, and by the fragment
     * renderer for fragment-scoped responses.
     */
    public function wrapWithEnvelope(string $html): string
    {
        if (!$this->persistsState() || $this->instanceId === null) {
            return $html;
        }

        // Avoid double-wrapping if the same instance ID is already at
        // the head of the HTML (e.g. when a fragment template happens
        // to start with one).
        if (str_contains(substr($html, 0, 200), 'data-hxid="' . $this->instanceId . '"')) {
            return $html;
        }

        $id       = htmlspecialchars($this->instanceId, ENT_QUOTES, 'UTF-8');
        $idKey    = config('htmx.instance_id_param', '_hxid');
        $fragsKey = config('htmx.fragments_param', '_hxfrags');
        $valueKey = config('htmx.value_property_param', '_hxvalue');
        $fullKey  = config('htmx.full_render_param', '_hxfull');

        $payload = [$idKey => $this->instanceId];
        if (!empty($this->renderFragments)) {
            $payload[$fragsKey] = implode(',', $this->renderFragments);
        }
        if ($this->renderValueProperty !== null) {
            $payload[$valueKey] = $this->renderValueProperty;
        }
        if ($this->forceFullRender) {
            $payload[$fullKey] = '1';
        }
        $vals = json_encode($payload, JSON_UNESCAPED_SLASHES);

        // hx-component on the envelope so the JS layer's compilePoll and
        // closest() walks can resolve the component name straight from
        // the wrapper. View-internal hx-component declarations still win
        // for nested triggers since closest() picks the nearest match.
        $compName = htmlspecialchars(
            lcfirst($this->reflectionMeta()['shortName']),
            ENT_QUOTES, 'UTF-8',
        );

        // Polling is declared per-component via $pollInterval / $pollAction
        // — emit those attrs only when both are set.
        $pollAttrs = '';
        if ($this->pollInterval !== null && $this->pollAction !== null) {
            $pollAttrs = sprintf(
                ' hx-poll="%d" hx-poll-action="%s"',
                (int) $this->pollInterval,
                htmlspecialchars($this->pollAction, ENT_QUOTES, 'UTF-8'),
            );
        }

        return sprintf(
            "<div data-hxid=\"%s\" hx-component=\"%s\" hx-vals='%s' hx-target=\"closest [data-hxid]\" hx-swap=\"outerHTML\"%s>%s</div>",
            $id, $compName, $vals, $pollAttrs, $html
        );
    }

    /**
     * Scan rendered HTML for hx-model bindings and return the names that
     * are NOT covered by #[Modelable] or the $modelable allowlist. The
     * kernel uses this to emit a dev-mode warning when a developer wires
     * up an input but forgets the server-side opt-in — those values were
     * about to be silently dropped from request hydration.
     *
     * @return string[]  names referenced by hx-model but not modelable
     */
    public function findUnboundModels(string $html): array
    {
        // Match hx-model and any dotted modifiers (hx-model.defer, etc.)
        if (!preg_match_all('/hx-model(?:\.[\w]+)*\s*=\s*["\']([^"\']+)["\']/', $html, $matches)) {
            return [];
        }

        $bound = array_unique($matches[1] ?? []);
        if (empty($bound)) {
            return [];
        }

        $allowed = array_unique(array_merge(
            $this->modelable,
            $this->reflectionMeta()['modelableProps'],
        ));

        return array_values(array_diff($bound, $allowed));
    }

    protected function isHtmxRequest(): bool
    {
        return app('request')->header('HX-Request') === 'true';
    }
}
