<?php

namespace Nitro\Livewire\Features;

use Closure;
use Nitro\Livewire\Hooks\ComponentHook;
use Nitro\Livewire\Hooks\UserHooks;

/**
 * @island — an isolated block inside a component that re-renders on its own.
 *
 * The saving over a region is that an island's body is a DEFERRED CLOSURE: when
 * an island is frozen (any re-render that is not targeting it) the closure is
 * never invoked, so the queries and loops inside it simply do not run. The
 * client sees a keep marker and leaves that DOM alone.
 *
 * Three modes per pass:
 *   render      — run the body and emit it
 *   placeholder — a lazy/defer island on the initial pass: emit the placeholder
 *                 and let the client request the real body
 *   skip        — frozen: emit only the keep marker
 */
class SupportsIslands extends ComponentHook
{
    /** The synthetic action the client sends to load a lazy/deferred island. */
    public const LOAD_METHOD = '__loadIsland';

    /** Initial pass: every island renders (or paints its placeholder). */
    protected bool $renderAll = false;

    /** Re-render pass: only this island renders; the rest freeze. */
    protected ?string $target = null;

    /**
     * Set the island render mode before a render pass. On the initial render all
     * islands render (or their placeholder if lazy/defer); on a re-render only the
     * targeted island renders and the rest freeze (their bodies are not executed).
     */
    public function begin(bool $renderAll, ?string $target = null): void
    {
        $this->renderAll = $renderAll;
        $this->target = $target;
    }

    /** The island this pass is scoped to, if any. */
    public function target(): ?string
    {
        return $this->target;
    }

    /**
     * Pull the island target out of a commit: either where the interaction
     * originated, or an explicit __loadIsland trigger. Returns the target and
     * the calls with that synthetic trigger removed.
     *
     * @return array{0: string|null, 1: array}
     */
    public function resolveTarget(array $calls, ?string $origin): array
    {
        $target = $origin;

        $calls = array_values(array_filter($calls, static function (array $call) use (&$target): bool {
            if (($call['method'] ?? '') === self::LOAD_METHOD) {
                $target = $call['params'][0] ?? $target;
                return false;
            }
            return true;
        }));

        return [$target, $calls];
    }

    /**
     * Render an @island block. Called from compiled views with the island's
     * deferred body (and optional placeholder) closures — the closure is only
     * invoked when this island should actually render.
     */
    public function island(string $name, array $options, array $scope, Closure $body, ?Closure $placeholder = null): string
    {
        $mode = $this->mode($name, $options);
        $attr = 'wire:island="' . htmlspecialchars($name, ENT_QUOTES) . '"';

        if ($mode === 'render') {
            $scope = array_merge($scope, (array) ($options['with'] ?? []));

            return '<div ' . $attr . '>' . $this->run($body, $scope) . '</div>';
        }

        if ($mode === 'placeholder') {
            $scope = array_merge($scope, (array) ($options['with'] ?? []));
            $html = $placeholder !== null ? $this->run($placeholder, $scope) : $this->placeholder();
            $flag = ($options['lazy'] ?? false) ? 'wire:island-lazy' : 'wire:island-defer';

            return '<div ' . $attr . ' ' . $flag . '>' . $html . '</div>';
        }

        // Frozen: emit a keep marker; the client leaves the existing island DOM.
        return '<div ' . $attr . ' wire:island-keep></div>';
    }

    /** Decide whether an island renders, shows a placeholder, or freezes this pass. */
    protected function mode(string $name, array $options): string
    {
        if ($this->renderAll) {
            return (($options['lazy'] ?? false) || ($options['defer'] ?? false)) ? 'placeholder' : 'render';
        }

        if ($options['always'] ?? false) {
            return 'render';
        }

        return $this->target === $name ? 'render' : 'skip';
    }

    /** Run an island body/placeholder closure with $this = component and its scope. */
    protected function run(Closure $closure, array $scope): string
    {
        return (string) $closure->call($this->component, $scope);
    }

    /** The fallback placeholder for a lazy/defer island with no @placeholder block. */
    protected function placeholder(): string
    {
        if (UserHooks::has($this->component, 'placeholder')) {
            return (string) UserHooks::invoke($this->component, 'placeholder');
        }

        return '<div class="animate-pulse rounded bg-slate-100 p-4 dark:bg-slate-800">&nbsp;</div>';
    }
}
