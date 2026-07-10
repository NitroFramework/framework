<?php

namespace Nitro\Htmx;

use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Container\Container;
use Nitro\Htmx\Support\RequestGuard;
use Nitro\Htmx\Support\ComponentResolver;
use Nitro\Htmx\Support\ArgumentResolver;
use Nitro\Htmx\RenderContext;

/**
 * The central request handler for all HTMX component interactions.
 * The server-side counterpart to hx-component.js.
 *
 * While the JS runtime compiles ergonomic attributes into HTMX requests,
 * this dispatcher receives those requests and routes them through the component
 * lifecycle. Together they form a complete round trip:
 *
 *   Browser:   hx-click="delete(5)"
 *        ↓     (JS compiles → HTMX fires POST /hx/todo/delete)
 *   Dispatch:  resolve("todo") → Todo::delete(5) → render → HTML response
 *        ↓     (HTMX swaps response into target)
 *   Browser:   DOM updated, re-compiled for new elements
 *
 * The developer-facing interface is the attribute API surface — developers
 * write hx-click, hx-model, hx-change and never touch URLs, HTTP methods,
 * or request plumbing. The dispatcher + JS runtime are the translation layer
 * that makes this abstraction work.
 *
 * Not a front-controller kernel like Http\Kernel — it runs INSIDE the HTTP
 * request to dispatch a component interaction; hence "dispatcher", not "kernel".
 *
 * Processes three types of requests:
 *   1. Page requests  — full page loads with layout wrapping
 *   2. HTMX actions   — partial responses from component actions
 *   3. Lazy loading   — deferred widget rendering via __lazy
 *
 * The dispatcher determines the rendering context and applies layout wrapping
 * automatically. Components just call render() — the dispatcher decides whether
 * to wrap with a layout (page requests) or return a partial (HTMX/embed).
 */
class HtmxDispatcher
{
    /** Action name used internally for lazy-loaded component requests */
    private const LAZY_ACTION = '__lazy';

    public function __construct(
        private Container $container,
        private RequestGuard $guard,
        private ComponentResolver $resolver,
        private ArgumentResolver $arguments,
    ) {}

    /**
     * Handle an incoming component request.
     *
     * Entry point for all HTMX component interactions. Determines the request
     * type (lazy, page, or HTMX action) and routes through the appropriate
     * lifecycle:
     *
     *   Page request:  onBoot → index() → applyAutoRender → response (wrapped in layout)
     *   HTMX action:   onBoot → onBeforeAction → action() → onAfterAction → applyAutoRender → response (partial)
     *   Lazy loading:  onBoot → onBeforeAction(__lazy) → onAfterAction(__lazy) → applyAutoRender → response (partial)
     *
     * onBoot() internally fires onMount() once on initial mount (when
     * loadState() finds no persisted snapshot), so onMount lives inside
     * onBoot rather than being a separate step.
     *
     * @param  Request $request       The incoming HTTP request
     * @param  string  $component     Component short name (e.g. 'Counter')
     * @param  string  $action        Action method to call (e.g. 'increment', 'index')
     * @param  bool    $isPageRequest Whether this is a full page route (skips HX-Request check)
     * @return Response
     */
    public function handle(Request $request, string $component, string $action, bool $isPageRequest = false): Response
    {
        // Run security checks: CSRF on non-GET, decrypt obfuscated hx-vals
        $this->guard->guard($request);

        // Resolve the component class from the short name
        $instance = $this->resolver->resolve($component);

        // ── Lazy loading path ──
        if ($action === self::LAZY_ACTION) {
            return $this->handleLazy($request, $instance);
        }

        // Validate that the action is a callable public method
        $this->resolver->assertCallable($instance, $action);

        // Per-action authorization hook. Default returns true; component
        // overrides authorize() to gate sensitive operations.
        if (!$instance->isAuthorizedFor($action)) {
            abort(403, "Action [{$action}] not authorized.");
        }

        // Determine if this is a page request (the router signals this)
        $isPage = $isPageRequest;

        // HTMX action requests must have the HX-Request header (unless disabled)
        if (!$isPage) {
            $this->guard->assertHtmx($request);
        }

        // ── Lifecycle ──
        // onBoot() handles instance-ID resolution, state hydration, and
        // fragment-scope propagation so HTMX actions stay inside the
        // fragments they were originally mounted with.
        $instance->onBoot();

        if (!$isPage) {
            $instance->onBeforeAction($action);
        }

        // Resolve action parameters from the request and invoke the action
        $args   = $this->arguments->resolve($instance, $action, $request);
        $result = $instance->$action(...$args);

        if (!$isPage) {
            $instance->onAfterAction($action, $result);
        }

        // Auto-render fallback: if nothing was explicitly produced and the
        // component opts into auto-render, queue a default render against
        // the convention view with public-property data.
        $this->applyAutoRender($instance, $result, $isPage, $action);

        // Persist public-property state for the next request (no-op when
        // $persistState = false on the component).
        $instance->persistState();

        // Build and return the HTTP response
        return $this->finalizeResponse($instance, $result, $isPage);
    }

    /**
     * Default-render the component's convention view if the action neither
     * called render()/value() nor returned a Response. Delegates the actual
     * "set a sensible render context" logic to the component itself, so
     * the kernel and the embedding renderer can't drift on subtle rules.
     *
     * Resolution order (first non-null wins):
     *   1. action returned a Response                 → pass-through
     *   2. action returned a scalar                   → wrap as HTML
     *   3. action called $this->render() / value()    → renderContext/renderValue set
     *   4. #[RenderValue] / #[RenderFragment] on action  → applyActionRenderAttributes
     *   5. default                                    → applyAutoRenderFallback (full view)
     */
    private function applyAutoRender(HtmxComponent $instance, mixed $result, bool $isPage = false, ?string $action = null): void
    {
        if ($result instanceof Response) {
            return;
        }
        if ($result !== null) {
            return; // Action returned a scalar — buildResponse wraps it as HTML.
        }
        if ($action !== null && $instance->applyActionRenderAttributes($action)) {
            return;
        }
        $instance->applyAutoRenderFallback($isPage);
    }

    /**
     * Handle a lazy-loaded component request.
     *
     * Called when HTMX fetches a component that was rendered with lazy: true.
     * Runs the standard widget lifecycle (onBoot → onMount) and builds
     * the response from the component's renderView/renderData set by render().
     *
     * @param  Request        $request   The incoming HTTP request
     * @param  HtmxComponent  $instance  The resolved component instance
     * @return Response
     */
    private function handleLazy(Request $request, HtmxComponent $instance): Response
    {
        $query = $request->query();
        $fragsParam = config('htmx.fragments_param', '_hxfrags');

        $instance->props = array_filter(
            $query,
            fn($k) => !in_array($k, ['_t', $fragsParam], true),
            ARRAY_FILTER_USE_KEY
        );

        // Symmetric lifecycle — same hooks as a regular HTMX action, with
        // __lazy as the action name so onBeforeAction/onAfterAction can
        // branch on it if a component cares. onBoot fires onMount() once
        // on initial mount so we don't double-invoke it here.
        $instance->onBoot();
        $instance->onBeforeAction(self::LAZY_ACTION);
        $instance->onAfterAction(self::LAZY_ACTION, null);

        // Auto-render fallback for lazy widgets whose onMount() didn't render.
        $this->applyAutoRender($instance, null);
        $instance->persistState();

        return $this->finalizeResponse($instance, null, false);
    }

    /**
     * Finalize the response: build it from the action result and apply event headers.
     *
     * @param  HtmxComponent $instance  The component instance
     * @param  mixed         $result    The return value from the action
     * @param  bool          $isPage    Whether this is a full page request
     * @return Response
     */
    private function finalizeResponse(HtmxComponent $instance, mixed $result, bool $isPage): Response
    {
        $response = $this->buildResponse($instance, $result, $isPage);

        // Apply any pending HX-Trigger event headers (emit, emitAfterSwap, etc.)
        if ($instance->hasPendingEvents()) {
            $instance->applyEventHeaders($response);
        }

        return $response;
    }

    /**
     * Build an HTTP response from the action result.
     *
     * Handles four cases:
     *   1. Action returned a Response directly → pass through
     *   2. Action called value(): $instance->renderValue is set → wrap as HTML, no envelope
     *   3. Action called render() (or auto-render set $renderContext) → render view,
     *      wrap in envelope, apply layout if page request
     *   4. Action returned a scalar value → wrap in HTML response
     *
     * Layout is only applied when:
     *   - This is a page request ($isPage = true)
     *   - The render context has a layout set via withLayout()
     *
     * HTMX actions never get layout wrapping, even if withLayout() was called.
     *
     * @param  HtmxComponent $instance  The component instance
     * @param  mixed         $result    The return value from the action (or null)
     * @param  bool          $isPage    Whether this is a page request
     * @return Response
     */
    private function buildResponse(HtmxComponent $instance, mixed $result, bool $isPage): Response
    {

        // Action already returned a complete Response
        if ($result instanceof Response) {
            return $result;
        }

        if ($result === null && $instance->renderValue !== null) {
            return Response::html((string) $instance->renderValue);
        }
        // Action called render() — use the RenderContext
        $ctx = $instance->renderContext;

        if ($result === null && $ctx !== null) {

            // Render specific fragments if requested
            $fragments = $ctx->fragments ?? $instance->renderFragments;
            if ($fragments !== null) {
                return $this->renderFragments($instance, $ctx, $fragments);
            }

            // Render the view
            $blade = $this->container->make('view');
            $html = $blade->render($ctx->view, $ctx->data);

            $this->warnIfUnboundModels($instance, $html);

            // Wrap stateful components in an instance-ID envelope so the
            // _hxid parameter rides along with every descendant HTMX request.
            // Done before layout injection so the wrapper lands inside the
            // layout's section, not around the entire page.
            $html = $instance->wrapWithEnvelope($html);

            // Wrap with layout for page requests (only if explicitly set via withLayout)
            if ($isPage && $ctx->hasLayout()) {
                $blade->forceSection($ctx->section, $html);
                $html = $blade->getFactory()->renderPartial($ctx->layout, $ctx->data);
            }

            return Response::html($html);
        }

        // Scalar value — wrap as HTML string
        return Response::html((string) $result);
    }

    /**
     * Surface unbound hx-model bindings to the developer. When debug is
     * on, any hx-model in the rendered output that points at a property
     * NOT marked #[Modelable] (or listed in $modelable) gets logged —
     * the value would otherwise be silently dropped during hydration,
     * which is a very confusing failure mode to debug live.
     */
    private function warnIfUnboundModels(HtmxComponent $instance, string $html): void
    {
        if (!config('app.debug', false)) {
            return;
        }

        $unbound = $instance->findUnboundModels($html);
        if (empty($unbound)) {
            return;
        }

        $class = get_class($instance);
        foreach ($unbound as $name) {
            error_log(
                "[htmx] hx-model=\"{$name}\" found in {$class} view, but \${$name} isn't "
                . "marked #[Modelable] (nor listed in \$modelable). Request input for "
                . "this field will be ignored. Add #[Modelable] above the property to fix."
            );
        }
    }

    /**
     * Render specific @fragment blocks from the component's view.
     *
     * @param  HtmxComponent  $instance
     * @param  RenderContext   $ctx
     * @param  array           $fragments
     * @return Response
     */
    private function renderFragments(HtmxComponent $instance, RenderContext $ctx, array $fragments): Response
    {
        $blade = $this->container->make('view');

        if (count($fragments) === 1) {
            $html = $blade->renderFragment($ctx->view, $fragments[0], $ctx->data);
        } else {
            $html = $blade->renderFragments($ctx->view, $fragments, $ctx->data);
        }

        $html = $instance->wrapWithEnvelope($html);

        return Response::html($html);
    }
}
