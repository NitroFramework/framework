<?php

namespace Nitro\Htmx;

use Nitro\Container\Container;
use Nitro\Htmx\Support\HxObfuscator;

/**
 * Renders an HTMX component for initial embedding in a full-page response.
 *
 * This is the non-HTMX code path — used when a Blade template wants to embed
 * a component on first load (before any HTMX requests are made).
 *
 * For HTMX requests (hx-get, hx-post, etc.) the HtmxDispatcher handles execution
 * and applies security checks (HX-Request header, CSRF). This renderer skips
 * those checks intentionally since it runs server-side during a normal page render.
 *
 * Supports four rendering strategies:
 *
 *   Eager (default)        — rendered inline during the page response
 *   Eager + Fragments      — only specified @fragment blocks rendered inline
 *   Lazy                   — placeholder div, full view fetched by HTMX
 *   Lazy + Fragments       — placeholder div, specific fragments fetched by HTMX
 *
 * Usage in Blade (use PHP 8 named args to skip unused positionals):
 *   @widget('Counter')                                        ← eager, full view
 *   @widget('Counter', props: ['studentId' => 123])           ← eager, full view with props
 *   @widget('Counter', fragments: ['counter', 'modals'])      ← eager, only these fragments
 *   @widget('Counter', lazy: true)                            ← lazy on page load
 *   @widget('Counter', lazy: 'intersect')                     ← lazy when scrolled into viewport
 *   @widget('Counter', fragments: ['counter'], lazy: true)    ← lazy + specific fragments
 *
 * Positional forms still work — see render() below — but named args
 * make the intent clearer when most positions are skipped.
 */
class HtmxComponentRenderer
{
    public function __construct(
        private Container $container,
        private string $componentNamespace,
    ) {}

    /**
     * Render a component using the appropriate strategy.
     *
     * Acts as a router — determines the rendering mode from the arguments
     * and delegates to the corresponding dedicated method.
     *
     *   // Eager, full view
     *   $renderer->render('Counter');
     *
     *   // Eager, only the 'counter' and 'modals' fragments
     *   $renderer->render('Counter', [], ['counter', 'modals']);
     *
     *   // Lazy load on page load, full view
     *   $renderer->render('Counter', ['studentId' => 5], lazy: true);
     *
     *   // Lazy load on viewport, only 'counter' fragment
     *   $renderer->render('Counter', [], ['counter'], lazy: 'intersect');
     *
     * @param  string      $name       Component short name (e.g. 'Counter')
     * @param  array       $props      Props passed to the component
     * @param  array|null  $fragments  If set, only render these @fragment blocks
     * @param  bool|string $lazy       false = eager, true = lazy on load, 'intersect' = lazy on viewport
     * @param  string|null $value      Embed-site override: every action returns scalar of this property
     * @param  bool        $full       Embed-site override: every action does full re-render (beats PHP attrs)
     * @return string                  Rendered HTML
     */
    public function render(
        string $name,
        array $props = [],
        ?array $fragments = null,
        bool|string $lazy = false,
        ?string $value = null,
        bool $full = false,
    ): string {
        $this->assertOverridesMutuallyExclusive($name, $fragments, $value, $full);

        $override = [
            'value' => $value,
            'full'  => $full,
        ];

        if ($lazy !== false) {
            return $fragments
                ? $this->renderLazyWithFragments($name, $props, $fragments, $lazy, $override)
                : $this->renderLazy($name, $props, $lazy, $override);
        }

        return $fragments
            ? $this->renderEagerWithFragments($name, $props, $fragments, $override)
            : $this->renderEager($name, $props, $override);
    }

    /**
     * Embed-site overrides are mutually exclusive — "render as value" and
     * "render as fragment X" and "render full" are three different intents
     * and combining them is meaningless. Catch it loudly at the call site
     * so the bug is obvious in the markup, not buried in runtime behavior.
     */
    private function assertOverridesMutuallyExclusive(
        string $name,
        ?array $fragments,
        ?string $value,
        bool $full,
    ): void {
        $declared = array_filter([
            'fragments' => $fragments !== null && $fragments !== [],
            'value'     => $value !== null,
            'full'      => $full,
        ]);
        if (count($declared) > 1) {
            throw new \InvalidArgumentException(
                "@widget('{$name}') has conflicting render overrides: "
                . implode(', ', array_keys($declared)) . ". "
                . "Pick one — value:, fragments:, or full: — they're mutually exclusive."
            );
        }
    }

    // ── Eager strategies ─────────────────────────────────────────────

    /**
     * Eager without fragments — render the full view inline.
     *
     *   @widget('Counter')
     *   @widget('Counter', ['studentId' => 123])
     *
     * The component goes through its full lifecycle (onBoot → onMount)
     * and the renderView set by embed() is rendered as a complete view.
     *
     * @param  string $name   Component short name
     * @param  array  $props  Props passed during embedding
     * @return string         Rendered HTML
     *
     * @throws \RuntimeException If the component finished its lifecycle without
     *                           establishing a renderView (auto-render disabled
     *                           AND no explicit render() call) — that's a bug,
     *                           not a "render nothing" intent.
     */
    private function renderEager(string $name, array $props, array $override = []): string
    {
        $component = $this->bootComponent($name, $props, $override);

        if (!$component->renderView) {
            throw new \RuntimeException(
                "@widget('{$name}') booted but produced no renderView. "
                . "Either enable auto-render (\$autoRender = true), call \$this->render(...) "
                . "in onMount(), or implement a view at the convention path."
            );
        }

        $html = (string) view($component->renderView, $component->renderData);
        return $component->wrapWithEnvelope($html);
    }

    /**
     * Eager with fragments — render only specified @fragment blocks inline.
     *
     *   @widget('Counter', [], ['counter'])
     *   @widget('Counter', [], ['counter', 'modals'])
     *
     * Useful when a view contains multiple @fragment blocks but only
     * a subset should be rendered during the initial page load.
     *
     * Fragment priority:
     *   1. Fragments set by the component itself (via embed()'s $fragments param)
     *   2. Fragments passed to @widget (the $fragments argument)
     *
     * @param  string $name       Component short name
     * @param  array  $props      Props passed during embedding
     * @param  array  $fragments  Fragment names to render (e.g. ['counter', 'modals'])
     * @return string             Rendered HTML
     *
     * @throws \RuntimeException If the component finished its lifecycle without
     *                           establishing a renderView — same reasoning as
     *                           renderEager().
     */
    private function renderEagerWithFragments(string $name, array $props, array $fragments, array $override = []): string
    {
        $component = $this->bootComponent($name, $props, $override);

        if (!$component->renderView) {
            throw new \RuntimeException(
                "@widget('{$name}', fragments: " . json_encode($fragments) . ") booted but produced no renderView. "
                . "Either enable auto-render (\$autoRender = true), call \$this->render(...) "
                . "in onMount(), or implement a view at the convention path."
            );
        }

        $activeFragments = $component->renderFragments ?? $fragments;
        // Stamp the scope onto the component so wrapWithInstanceEnvelope
        // emits _hxfrags — every subsequent interaction stays scoped.
        $component->renderFragments = $activeFragments;

        $blade = app('view');

        if (count($activeFragments) === 1) {
            $html = $blade->renderFragment(
                $component->renderView,
                $activeFragments[0],
                $component->renderData
            );
        } else {
            $html = $blade->renderFragments(
                $component->renderView,
                $activeFragments,
                $component->renderData
            );
        }

        return $component->wrapWithEnvelope($html);
    }

    // ── Lazy strategies ──────────────────────────────────────────────

    /**
     * Lazy without fragments — placeholder that fetches the full view via HTMX.
     *
     *   @widget('Counter', [], lazy: true)              ← fetched on page load
     *   @widget('Counter', [], lazy: 'intersect')       ← fetched when scrolled into viewport
     *
     * Outputs a <div> with hx-get pointing to the __lazy action. HTMX
     * replaces the placeholder with the full rendered view via outerHTML swap.
     *
     * @param  string      $name   Component short name
     * @param  array       $props  Props passed during embedding
     * @param  bool|string $lazy   true = trigger on load, 'intersect' = trigger on viewport
     * @return string              Placeholder HTML with hx-get attributes
     */
    private function renderLazy(string $name, array $props, bool|string $lazy, array $override = []): string
    {
        return $this->buildLazyPlaceholder($name, $props, null, $lazy, $override);
    }

    /**
     * Lazy with fragments — placeholder that fetches specific @fragment blocks via HTMX.
     *
     *   @widget('Counter', [], ['counter'], lazy: true)
     *   @widget('Counter', [], ['counter', 'modals'], lazy: 'intersect')
     *
     * Same as renderLazy() but encodes the fragment names into the URL
     * as a _fragments query parameter. The HtmxDispatcher reads this and
     * sets renderFragments after the component lifecycle completes.
     *
     * @param  string      $name       Component short name
     * @param  array       $props      Props passed during embedding
     * @param  array       $fragments  Fragment names to render
     * @param  bool|string $lazy       true = trigger on load, 'intersect' = trigger on viewport
     * @return string                  Placeholder HTML with hx-get attributes
     */
    private function renderLazyWithFragments(string $name, array $props, array $fragments, bool|string $lazy, array $override = []): string
    {
        return $this->buildLazyPlaceholder($name, $props, $fragments, $lazy, $override);
    }

    // ── Shared helpers ───────────────────────────────────────────────

    /**
     * Boot a component through its full lifecycle and return the instance.
     *
     * Resolves the class from the component namespace, validates it extends
     * HtmxComponent, then runs onBoot() → onMount(). Used by both eager
     * rendering strategies.
     *
     * @param  string $name   Component short name (e.g. 'Counter')
     * @param  array  $props  Props to inject before lifecycle runs
     * @return HtmxComponent  The fully booted component instance
     *
     * @throws \RuntimeException If the class doesn't exist or isn't an HtmxComponent
     */
    private function bootComponent(string $name, array $props, array $override = []): HtmxComponent
    {
        $class = $this->componentNamespace . ucfirst($name);

        if (!class_exists($class) || !is_subclass_of($class, HtmxComponent::class)) {
            throw new \RuntimeException("HTMX Component [{$name}] not found.");
        }

        $component = $this->container->make($class);
        $component->props = $props;

        // Stamp embed-site render overrides BEFORE onBoot so resolveRenderOverrides()
        // doesn't overwrite them with a stale value from the request, and so
        // applyAutoRenderFallback() / wrapWithEnvelope() see them immediately.
        if (($override['value'] ?? null) !== null) {
            $component->renderValueProperty = $override['value'];
        }
        if (!empty($override['full'])) {
            $component->forceFullRender = true;
            $component->renderFragments = null;
        }

        // onBoot fires onMount() once on initial mount via $justMounted.
        $component->onBoot();

        // Auto-render fallback — same path the kernel uses for action
        // responses, so eager embeds and HTMX actions can't drift on
        // subtle rules (skipRender, fragment scope, layout).
        $component->applyAutoRenderFallback();
        $component->persistState();

        return $component;
    }

    /**
     * Build the lazy placeholder <div> that HTMX will swap with the real component.
     *
     * Constructs an obfuscated URL pointing to the __lazy action, encodes
     * props (and optionally fragments) as query parameters, resolves the
     * placeholder HTML, and assembles the final div.
     *
     * Output example:
     *   <div hx-get="/hx/a1b2c3/d4e5f6?studentId=5&_fragments=counter"
     *        hx-trigger="load" hx-swap="outerHTML">Loading...</div>
     *
     * @param  string      $name       Component short name
     * @param  array       $props      Props to encode as query parameters
     * @param  array|null  $fragments  Fragment names to encode (null = full view)
     * @param  bool|string $lazy       true = 'load' trigger, 'intersect' = 'intersect once' trigger
     * @return string                  Placeholder HTML
     *
     * @throws \RuntimeException If the class doesn't exist or isn't an HtmxComponent
     */
    private function buildLazyPlaceholder(string $name, array $props, ?array $fragments, bool|string $lazy, array $override = []): string
    {
        $class = $this->componentNamespace . ucfirst($name);

        if (!class_exists($class) || !is_subclass_of($class, HtmxComponent::class)) {
            throw new \RuntimeException("HTMX Component [{$name}] not found.");
        }

        $obfuscator = $this->container->make(HxObfuscator::class);
        $normalized  = lcfirst($name);

        $hashedComp   = $obfuscator->obfuscate($normalized);
        $hashedAction = $obfuscator->obfuscateAction('__lazy', $normalized);
        $prefix       = config('htmx.route_prefix', '/hx');
        $url          = $prefix . '/' . $hashedComp . '/' . $hashedAction;

        $trigger = $lazy === 'intersect' ? 'intersect once' : 'load';

        // Encode props and fragments into the query string. Fragments
        // use the same param name as the hx-vals round-trip — once the
        // lazy view loads, the wrapper continues carrying _hxfrags.
        $queryParams = $props;
        if (!empty($fragments)) {
            $queryParams[config('htmx.fragments_param', '_hxfrags')] = implode(',', $fragments);
        }
        if (($override['value'] ?? null) !== null) {
            $queryParams[config('htmx.value_property_param', '_hxvalue')] = $override['value'];
        }
        if (!empty($override['full'])) {
            $queryParams[config('htmx.full_render_param', '_hxfull')] = '1';
        }

        $queryString = !empty($queryParams) ? '?' . http_build_query($queryParams) : '';

        $placeholder = $this->resolvePlaceholder($class);

        // hx-target="this" pins the swap to the placeholder itself.
        // Without it, HTMX inherits hx-target from an ancestor (e.g. a
        // parent component's envelope) — and on outerHTML swap that
        // would replace the ANCESTOR instead of the placeholder, wiping
        // out every sibling widget. This was the "other widgets flash
        // then disappear" symptom when a lazy widget sat inside another
        // component's wrapper.
        return sprintf(
            '<div hx-get="%s%s" hx-trigger="%s" hx-target="this" hx-swap="outerHTML">%s</div>',
            htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($queryString, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($trigger, ENT_QUOTES, 'UTF-8'),
            $placeholder
        );
    }

    /**
     * Resolve the placeholder HTML for a lazy-loaded component.
     *
     * Resolution priority:
     *   1. Component's $lazyPlaceholder property (Blade view name)
     *   2. Global config htmx.lazy_placeholder (Blade view name)
     *   3. Default inline "Loading..." indicator
     *
     * Example — component-level placeholder:
     *   class Counter extends HtmxComponent {
     *       public string $lazyPlaceholder = 'components.counter-skeleton';
     *   }
     *
     * Example — global config:
     *   // config/htmx.php
     *   'lazy_placeholder' => 'partials.loading-spinner',
     *
     * @param  string $class  Fully qualified component class name
     * @return string         Placeholder HTML
     */
    private function resolvePlaceholder(string $class): string
    {
        $default = '<div class="htmx-indicator" style="padding:1rem;text-align:center;">Loading...</div>';

        // Check component-level placeholder — read the default via reflection
        // instead of instantiating, so we don't pay for a full component
        // construction just to find out the view name.
        if (property_exists($class, 'lazyPlaceholder')) {
            $viewName = (new \ReflectionClass($class))->getDefaultProperties()['lazyPlaceholder'] ?? null;
            if ($viewName) {
                try {
                    return app('view')->render($viewName);
                } catch (\Throwable) {
                    return $default;
                }
            }
        }

        // Check global config
        $globalPlaceholder = config('htmx.lazy_placeholder');
        if ($globalPlaceholder) {
            try {
                return app('view')->render($globalPlaceholder);
            } catch (\Throwable) {
                return $default;
            }
        }

        return $default;
    }
}