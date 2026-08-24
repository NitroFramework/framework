<?php

namespace Nitro\Livewire\Hooks;

use Nitro\Livewire\Component;
use Nitro\Livewire\Features\SupportsComputedProperties;
use Nitro\Livewire\Features\SupportsFileUploads;
use Nitro\Livewire\Features\SupportsIslands;
use Nitro\Livewire\Features\SupportsLazyLoading;
use Nitro\Livewire\Features\SupportsLockedProperties;
use Nitro\Livewire\Features\SupportsUrlBindings;
use Nitro\Livewire\Features\SupportsValidation;
use RuntimeException;

/**
 * The dispatcher for a single component's framework hooks — one instance of
 * every registered {@see ComponentHook} bound to that component, plus the
 * nullary lifecycle fan-out the runtime calls into.
 *
 * The bag is created lazily by the component itself (Component::hooks()), not
 * by the manager, so a component constructed directly — `new Counter()` in a
 * test, or an SFC's anonymous class — has its features working just as a
 * managed one does.
 *
 * The registry is static and global: an application (or a package) adds its own
 * feature with ComponentHooks::register(MyFeature::class) from a provider.
 * Registration order is dispatch order.
 */
class ComponentHooks
{
    /**
     * The framework's own features, in dispatch order.
     *
     * @var array<int, class-string<ComponentHook>>
     */
    protected static array $registered = [
        SupportsValidation::class,
        SupportsComputedProperties::class,
        SupportsLockedProperties::class,
        SupportsFileUploads::class,
        SupportsUrlBindings::class,
        SupportsLazyLoading::class,
        SupportsIslands::class,
    ];

    /** @var array<class-string<ComponentHook>, ComponentHook> This component's hook instances. */
    protected array $hooks = [];

    public function __construct(protected Component $component)
    {
        foreach (static::$registered as $class) {
            /** @var ComponentHook $hook */
            $hook = new $class();
            $hook->setComponent($component);
            $this->hooks[$class] = $hook;
        }
    }

    /** Register an additional feature hook (from a service provider). */
    public static function register(string $hook): void
    {
        if (! is_subclass_of($hook, ComponentHook::class)) {
            throw new RuntimeException("Livewire hook [{$hook}] must extend " . ComponentHook::class . '.');
        }

        if (! in_array($hook, static::$registered, true)) {
            static::$registered[] = $hook;
        }
    }

    /** @return array<int, class-string<ComponentHook>> The registered hooks (introspection/tests). */
    public static function registered(): array
    {
        return static::$registered;
    }

    /**
     * This component's instance of a given hook.
     *
     * @template T of ComponentHook
     * @param  class-string<T>  $class
     * @return T
     */
    public function get(string $class): ComponentHook
    {
        if (! isset($this->hooks[$class])) {
            throw new RuntimeException("Livewire hook [{$class}] is not registered.");
        }

        /** @var T */
        return $this->hooks[$class];
    }

    // ─── Lifecycle fan-out ──────────────────────────────────────────────────

    public function boot(): void
    {
        foreach ($this->hooks as $hook) {
            $hook->boot();
        }
    }

    public function mount(array $params): void
    {
        foreach ($this->hooks as $hook) {
            $hook->mount($params);
        }
    }

    public function hydrate(array $memo): void
    {
        foreach ($this->hooks as $hook) {
            $hook->hydrate($memo);
        }
    }

    public function render(string $html): string
    {
        foreach ($this->hooks as $hook) {
            $html = $hook->render($html);
        }

        return $html;
    }

    public function dehydrate(array &$memo): void
    {
        foreach ($this->hooks as $hook) {
            $hook->dehydrate($memo);
        }
    }

    public function destroy(): void
    {
        foreach ($this->hooks as $hook) {
            $hook->destroy();
        }
    }
}
