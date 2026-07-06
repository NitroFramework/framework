<?php

namespace Nitro\Thrust\Concerns;

use Nitro\PerformanceBar\PerformanceMetrics;
use Nitro\View\Compiler\CompiledTemplateCache;
use Nitro\View\Engine\ViewRenderer;
use Nitro\Thrust\WorkerMode;

/**
 * Resets per-request container state between worker iterations.
 *
 * Persistent services (router, view, config, …) survive the reset so each
 * subsequent request only pays for request-scoped work.
 */
trait ResetsForWorkerMode
{
    /**
     * Reset request-scoped container singletons + framework statics so the
     * next request starts from a clean slate without paying the full
     * bootstrap cost again.
     */
    public function resetForWorkerMode(?WorkerMode $config = null): void
    {
        $scoped = $config?->scopedServices ?? ['request', 'auth', 'db', 'session'];

        // Container::forgetScoped clears resolved instances but keeps the
        // bindings, so the next get('request') will re-resolve from scratch.
        $this->container->forgetScoped($scoped);

        // Flush every binding that declared itself scoped() (e.g. the session
        // Store). Features opt into per-request reset at bind time instead of
        // being added to the list above — the same model as Laravel Octane.
        $this->container->forgetScopedInstances();

        // Reset PerformanceMetrics so the @elapsed_time directive measures
        // THIS request, not the worker's uptime.
        PerformanceMetrics::reset();

        // Drop any directive-cache memoization that might have grown during
        // the request. The compiler's per-source freshness verdicts are
        // request-lifetime caches.
        if (class_exists(CompiledTemplateCache::class)
            && $this->container->has(CompiledTemplateCache::class)) {
            try {
                $this->container->get(CompiledTemplateCache::class)
                    ->clearFreshnessCache();
            } catch (\Throwable) {
                // Non-fatal — keep serving.
            }
        }

        // The view renderer is a persistent singleton (its compiled-template
        // cache is expensive to rebuild), but its per-render state — sections,
        // stacks, fragments, teleports — is request-lifetime. Without an
        // explicit flush, content captured during one worker iteration leaks
        // into the next: stale `@push` payloads land in this request's
        // `@stack`, prior fragments answer fragment lookups they shouldn't,
        // etc. flushState() zeros all four maps without disturbing compiler
        // caches. We reach for ViewRenderer directly (not the 'view' alias,
        // which points at the Blade facade) because flushState lives on the
        // renderer itself.
        if (class_exists(ViewRenderer::class)
            && $this->container->has(ViewRenderer::class)) {
            try {
                $this->container->get(ViewRenderer::class)->flushState();
            } catch (\Throwable) {
                // Non-fatal — keep serving.
            }
        }
    }
}
