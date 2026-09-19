<?php

namespace Nitro\Livewire\Runtime;

use Nitro\Container\Contracts\ContainerInterface as Container;
use Nitro\Livewire\Component;
use Nitro\Livewire\Features\SupportsIslands;
use Nitro\Livewire\Features\SupportsLazyLoading;
use Nitro\Livewire\Hooks\PropertyHooks;
use Nitro\Livewire\Hooks\UserHooks;
use Nitro\Livewire\Snapshot\Snapshotter;

/**
 * The per-commit PHASE SEQUENCE, and nothing else.
 *
 * Every round trip from the browser lands here and walks the same ordered
 * phases — hydrate, deferred mount, resolve the island target, apply property
 * updates, invoke actions, render, dehydrate, shape the effects. Each phase is
 * one call into the piece that owns that concern, so this file stays readable as
 * the *order* of things; no phase's logic lives here.
 */
class UpdatePipeline
{
    protected PropertyHooks $properties;

    public function __construct(
        protected Container $container,
        protected Snapshotter $snapshotter,
        protected Renderer $renderer,
        protected ActionInvoker $actions,
    ) {
        $this->properties = new PropertyHooks();
    }

    /**
     * Handle a batch of update commits from the browser.
     *
     * @param array $payload  { components: [ { snapshot, updates, calls } ] }
     * @return array          { components: [ { snapshot, effects: { html } } ] }
     */
    public function update(array $payload): array
    {
        $components = [];

        foreach (($payload['components'] ?? []) as $commit) {
            $components[] = $this->handleCommit($commit);
        }

        return ['components' => $components];
    }

    /** Process a single component commit → { snapshot, effects }. */
    public function handleCommit(array $commit): array
    {
        $snapshot = $this->snapshotter->decode($commit['snapshot'] ?? []);
        $memo = $snapshot['memo'] ?? [];

        // 1. Hydrate — checksum-verified, state and feature memo restored.
        $component = $this->snapshotter->fromSnapshot($snapshot);

        $component->hooks()->boot();
        UserHooks::call($component, 'boot');
        UserHooks::call($component, 'hydrate');

        $calls = $commit['calls'] ?? [];

        // 2. Deferred mount — a #[Lazy] component's real mount() runs now.
        $calls = $this->runDeferredMount($component, $memo, $calls);

        // 3. Island target — from the originating island or a __loadIsland trigger.
        [$islandTarget, $calls] = $component->hooks()
            ->get(SupportsIslands::class)
            ->resolveTarget($calls, $commit['island'] ?? null);

        // 4. Property updates — #[Locked] guard + updating/updated hooks.
        $this->properties->apply($component, (array) ($commit['updates'] ?? []));

        // 5. Actions.
        $lastAction = null;
        foreach ($calls as $call) {
            $method = (string) ($call['method'] ?? '');
            $this->actions->call($component, $method, (array) ($call['params'] ?? []));
            if ($method !== '') {
                $lastAction = $method;
            }
        }

        if ($lastAction !== null) {
            $this->applyRenderAttributes($component, $lastAction);
        }

        UserHooks::call($component, 'booted');

        // 6. Render — only the targeted island renders; the rest freeze.
        $component->beginIslandRender(false, $islandTarget);

        // Render, then dehydrate — the snapshot reflects post-render state.
        $html = $this->renderer->render($component);
        $newSnapshot = $this->snapshotter->snapshot($component);
        $html = $this->renderer->wrapRoot($html, $component->getId(), $newSnapshot);

        // 7. Effects.
        return [
            'snapshot' => $newSnapshot,
            'effects'  => $this->effects($component, $commit, $html),
        ];
    }

    /**
     * A lazy component's deferred mount: run it with the parameters signed into
     * the memo, then drop the synthetic __lazyLoad call that triggered it.
     */
    protected function runDeferredMount(Component $component, array $memo, array $calls): array
    {
        $lazy = $component->hooks()->get(SupportsLazyLoading::class);

        if (! $lazy->isDeferred($memo)) {
            return $calls;
        }

        if (method_exists($component, 'mount')) {
            $this->container->call([$component, 'mount'], $lazy->deferredParams($memo));
        }

        $component->hooks()->mount($lazy->deferredParams($memo));

        return $lazy->stripLoadCall($calls);
    }

    /** Build the client-facing effects: html (or a region), dispatches, redirect. */
    protected function effects(Component $component, array $commit, string $html): array
    {
        $effects = [
            'html'       => $html,
            'dispatches' => $component->dispatchesToArray(),
        ];

        // Scope the response to one region — from #[RenderRegion]/renderRegion()
        // or from where the interaction originated (the commit's region).
        $region = $component->pullRegion() ?? ($commit['region'] ?? null);
        if ($region !== null && $region !== '') {
            $regionHtml = $this->renderer->extractRegion($html, (string) $region);
            if ($regionHtml !== null) {
                $effects['region'] = ['name' => $region, 'html' => $regionHtml];
                unset($effects['html']);
            }
        }

        if (($redirect = $component->redirectToArray()) !== null) {
            $effects['redirect'] = $redirect['url'];
            $effects['redirectUsingNavigate'] = $redirect['navigate'];
        }

        return $effects;
    }

    /**
     * Scope an action's re-render to a region when it declares #[RenderRegion]
     * — unless the action already called renderRegion() explicitly.
     */
    protected function applyRenderAttributes(Component $component, string $action): void
    {
        if ($component->pullRegion() !== null) {
            return;
        }

        if (($region = $this->actions->renderRegionFor($component, $action)) !== null) {
            $component->renderRegion($region);
        }
    }
}
