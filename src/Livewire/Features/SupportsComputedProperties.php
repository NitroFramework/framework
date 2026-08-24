<?php

namespace Nitro\Livewire\Features;

use Nitro\Livewire\Hooks\ComponentHook;
use Nitro\Livewire\Hooks\UserHooks;
use ReflectionMethod;

/**
 * #[Computed] methods read as properties: `$this->total` in the view resolves
 * the component's `total()` method once and reuses the value for the rest of
 * the request.
 *
 * The memo is per-component (this hook instance is), so it is naturally
 * request-scoped: a computed property is never carried in the snapshot, and the
 * next commit recomputes it from the freshly hydrated state.
 */
class SupportsComputedProperties extends ComponentHook
{
    /** Resolved values for this request, keyed by property name. */
    protected array $resolved = [];

    /** @var array<class-string, array<string, bool>> Memoized #[Computed] flags per class. */
    private static array $computedCache = [];

    /** Whether $name is a #[Computed] method on the component. */
    public function has(string $name): bool
    {
        $class = $this->component::class;

        if (isset(self::$computedCache[$class][$name])) {
            return self::$computedCache[$class][$name];
        }

        $computed = method_exists($this->component, $name)
            && (new ReflectionMethod($this->component, $name))
                ->getAttributes(\Nitro\Livewire\Attributes\Computed::class) !== [];

        return self::$computedCache[$class][$name] = $computed;
    }

    /** Whether a value for $name has already been resolved this request. */
    public function isResolved(string $name): bool
    {
        return array_key_exists($name, $this->resolved);
    }

    /** Resolve a computed property, memoizing it for the rest of the request. */
    public function get(string $name): mixed
    {
        if (array_key_exists($name, $this->resolved)) {
            return $this->resolved[$name];
        }

        return $this->resolved[$name] = UserHooks::invoke($this->component, $name);
    }

    /** Drop a memoized value (or all of them) so the next read recomputes. */
    public function forget(?string $name = null): void
    {
        if ($name === null) {
            $this->resolved = [];
            return;
        }

        unset($this->resolved[$name]);
    }
}
