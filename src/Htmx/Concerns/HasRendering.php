<?php

namespace Nitro\Htmx\Concerns;

use Nitro\Http\Response;
use Nitro\Htmx\RenderContext;
use Nitro\Htmx\Concerns\HasAutoState;

trait HasRendering
{
    /** Set by skipRender() so the kernel suppresses auto-render for this action. */
    protected bool $skipRender = false;

    /**
     * Mark this action as not requiring an auto-render. The kernel will
     * skip the default render pass even when no explicit render() ran.
     *
     *   public function logVisit(): void {
     *       AuditLog::record(...);
     *       $this->skipRender();   // fire-and-forget — no DOM update needed
     *   }
     */
    protected function skipRender(): void
    {
        $this->skipRender = true;
    }

    /**
     * Escape the inherited fragment scope and render the component's full
     * view for this response. Use when a widget was mounted in fragment
     * mode but an action genuinely needs to "expand" into the full layout
     * (e.g. open a modal that lives in another fragment).
     *
     * The wrapper will stop carrying _hxfrags from this point on.
     */
    protected function fullRender(): void
    {
        $this->renderFragments = null;
    }

    public function shouldSkipRender(): bool
    {
        return $this->skipRender;
    }

    /**
     * Declare what view to render.
     *
     * Used in ALL contexts — page loads, HTMX actions, and widget embedding.
     * The component just says "render this view." The kernel/renderer decides
     * the final response based on request context.
     *
     * Returns a RenderContext for optional chaining:
     *
     *   // Partial (HTMX action, widget embed)
     *   $this->render('students.index', $data);
     *
     *   // Full page with layout
     *   $this->render('students.index', $data)->withLayout('layouts.app');
     *
     *   // Full page with layout + custom section
     *   $this->render('students.index', $data)->withLayout('layouts.app')->withSection('main');
     *
     *   // Specific fragments only
     *   $this->render('students.index', $data)->withFragments(['table']);
     */
    protected function render(string $view, array $data = []): RenderContext
    {
        $context = new RenderContext($view, $data);

        // Store on the component for the kernel to read
        $this->renderContext = $context;

        // Also set legacy properties for backward compatibility
        // (HtmxComponentRenderer reads these for @widget embedding)
        $this->renderView = $view;
        $this->renderData = $data;

        return $context;
    }

    /**
     * Render only the specified @fragment blocks from the component's
     * default view, with default view-data (reflected public properties +
     * with()). Concise replacement for the boilerplate:
     *
     *   $this->render($this->resolveDefaultView(), $this->viewData())
     *        ->withFragments([...]);
     *
     * Usage:
     *   $this->only('weather-results');
     *   $this->only('table', 'pagination');
     */
    protected function only(string ...$fragments): RenderContext
    {
        $view = $this->resolveDefaultView();
        $data = $this->viewData();

        $context = new RenderContext($view, $data);
        $context->withFragments($fragments);

        $this->renderContext = $context;
        $this->renderView = $view;
        $this->renderData = $data;

        return $context;
    }

    /**
     * Render a single named @fragment from the component's view.
     * Lower-level than only() — useful when you need to pass custom data
     * that isn't the default viewData().
     */
    protected function renderFragment(string $fragment, array $data = []): RenderContext
    {
        $view = $this->renderView ?? $this->view ?? null;

        if ($view === null) {
            throw new \RuntimeException('No view set. Call render() first or define a $view property.');
        }

        $context = new RenderContext($view, $data);
        $context->withFragments([$fragment]);

        $this->renderContext = $context;
        $this->renderData = $data;

        return $context;
    }

    /**
     * Return a scalar value as an HTML response.
     *
     *   return $this->value($count);
     */
    protected function value(mixed $value): void
    {
        $this->renderValue = $value;
    }

    /**
     * Tell HTMX to navigate the browser to $url. Returns a Response the
     * action should return directly:
     *
     *   public function save(): Response {
     *       $this->model->save();
     *       return $this->redirectTo('/dashboard');
     *   }
     *
     * Note the name — redirect/refresh/reload are common action names
     * that user components might legitimately want to use, so framework
     * helpers use a verbier name to keep collisions unlikely.
     */
    protected function redirectTo(string $url): Response
    {
        return Response::html('')->header('HX-Redirect', $url);
    }

    /**
     * Tell HTMX to reload the current page from the server. Useful after
     * a destructive action where partial swaps would be a lie.
     */
    protected function reloadPage(): Response
    {
        return Response::html('')->header('HX-Refresh', 'true');
    }
}
