<?php

namespace Nitro\Livewire\Features;

use Nitro\Livewire\Attributes\Lazy;
use Nitro\Livewire\Hooks\ComponentHook;
use Nitro\Livewire\Hooks\UserHooks;
use ReflectionObject;

/**
 * #[Lazy] — the component paints a placeholder on the first render and defers
 * its mount() and real render to an immediate follow-up commit, so an expensive
 * component never blocks the page it sits on.
 *
 * The mount parameters ride along in the (checksum-signed) memo, which is what
 * lets the deferred mount run with exactly the arguments the initial render was
 * given. The client kicks the follow-up off with a synthetic `__lazyLoad` call
 * that never reaches user code — it is consumed here.
 */
class SupportsLazyLoading extends ComponentHook
{
    /** The synthetic action the client sends to trigger the deferred mount. */
    public const LOAD_METHOD = '__lazyLoad';

    /** Whether the component opts into lazy loading via #[Lazy]. */
    public function isLazy(): bool
    {
        return (new ReflectionObject($this->component))->getAttributes(Lazy::class) !== [];
    }

    /** Whether a commit's memo says this component is still awaiting its deferred mount. */
    public function isDeferred(array $memo): bool
    {
        return ! empty($memo['lazy']);
    }

    /** The mount parameters parked in the memo by the placeholder render. */
    public function deferredParams(array $memo): array
    {
        return (array) ($memo['lazyParams'] ?? []);
    }

    /** The extra memo entries the placeholder render writes into the snapshot. */
    public function deferMemo(array $params): array
    {
        return ['lazy' => true, 'lazyParams' => $params];
    }

    /** Drop the synthetic __lazyLoad call so it never reaches the action invoker. */
    public function stripLoadCall(array $calls): array
    {
        return array_values(array_filter(
            $calls,
            static fn(array $call): bool => ($call['method'] ?? '') !== self::LOAD_METHOD
        ));
    }

    /** The placeholder HTML shown before a lazy component loads. */
    public function placeholder(): string
    {
        if (UserHooks::has($this->component, 'placeholder')) {
            return (string) UserHooks::invoke($this->component, 'placeholder');
        }

        return '<div class="animate-pulse rounded-lg bg-slate-100 p-6 dark:bg-slate-800">&nbsp;</div>';
    }
}
