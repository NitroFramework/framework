<?php

namespace Nitro\Livewire\Features;

use Nitro\Livewire\Attributes\Locked;
use Nitro\Livewire\Component;
use Nitro\Livewire\Exceptions\CannotUpdateLockedProperty;
use Nitro\Livewire\Hooks\ComponentHook;
use ReflectionProperty;

/**
 * #[Locked] — a public property the browser may READ (it is in the snapshot)
 * but may never WRITE. The record id an action operates on is the canonical
 * case: it has to survive in the state, and a forged `updates` entry pointing
 * it at someone else's row must be rejected rather than silently applied.
 *
 * The guard runs on every incoming update ({@see \Nitro\Livewire\Hooks\PropertyHooks}),
 * before the value reaches the property.
 */
class SupportsLockedProperties extends ComponentHook
{
    /** @var array<class-string, array<string, bool>> Memoized #[Locked] flags per class. */
    private static array $lockedCache = [];

    /**
     * Whether the root property behind an update key is marked #[Locked].
     * Nested keys (form.x) resolve to their root property.
     */
    public static function isLocked(Component $component, string $key): bool
    {
        $root = str_contains($key, '.') ? explode('.', $key, 2)[0] : $key;
        $class = $component::class;

        if (! isset(self::$lockedCache[$class][$root])) {
            self::$lockedCache[$class][$root] = property_exists($component, $root)
                && (new ReflectionProperty($component, $root))->getAttributes(Locked::class) !== [];
        }

        return self::$lockedCache[$class][$root];
    }

    /** Reject an incoming update that targets a #[Locked] property. */
    public static function assertNotLocked(Component $component, string $key): void
    {
        if (static::isLocked($component, $key)) {
            throw new CannotUpdateLockedProperty($key);
        }
    }
}
