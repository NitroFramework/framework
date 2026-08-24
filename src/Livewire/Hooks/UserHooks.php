<?php

namespace Nitro\Livewire\Hooks;

use Nitro\Livewire\Component;

/**
 * The *convention* half of the hook system: lifecycle methods an application
 * defines on its own component by name — boot(), booted(), hydrate(),
 * dehydrate(), rendering(), rendered($html), mount(...) — which exist only if
 * the developer wrote them.
 *
 * Framework features never go through here (they are {@see ComponentHook}
 * classes); this is purely the method_exists() bridge to user code, kept in one
 * place so the runtime never scatters method_exists() checks around.
 */
class UserHooks
{
    /** Lifecycle names the runtime fires, for reference/introspection. */
    public const LIFECYCLE = ['boot', 'booted', 'hydrate', 'dehydrate', 'rendering', 'rendered', 'destroy'];

    /** Invoke an optional lifecycle method if the component defines it. */
    public static function call(Component $component, string $hook, mixed ...$args): mixed
    {
        if (! method_exists($component, $hook)) {
            return null;
        }

        return $component->{$hook}(...$args);
    }

    /** Whether the component defines the given lifecycle method. */
    public static function has(Component $component, string $hook): bool
    {
        return method_exists($component, $hook);
    }

    /**
     * Call a component method REGARDLESS of visibility.
     *
     * Conventional members like rules(), placeholder() and #[Computed] methods
     * are routinely declared protected — they are the component talking to the
     * framework, not to the browser. A feature hook is a separate object, so it
     * goes through reflection to reach them.
     */
    public static function invoke(Component $component, string $method, mixed ...$args): mixed
    {
        if (! method_exists($component, $method)) {
            return null;
        }

        return (new \ReflectionMethod($component, $method))->invoke($component, ...$args);
    }

    /** Read a component property regardless of visibility (e.g. a protected $rules). */
    public static function read(Component $component, string $property): mixed
    {
        if (! property_exists($component, $property)) {
            return null;
        }

        return (new \ReflectionProperty($component, $property))->getValue($component);
    }
}
