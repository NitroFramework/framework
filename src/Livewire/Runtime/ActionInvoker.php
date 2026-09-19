<?php

namespace Nitro\Livewire\Runtime;

use Nitro\Container\Contracts\ContainerInterface as Container;
use Nitro\Livewire\Attributes\RenderRegion;
use Nitro\Livewire\Component;
use Nitro\Validation\ValidationException;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;

/**
 * The gate every browser-initiated method call passes through.
 *
 * A commit names a method; this decides whether that name is allowed to run at
 * all. Only a PUBLIC, NON-STATIC method DECLARED ON THE SUBCLASS qualifies —
 * which rules out the base class's own API (setProperty, setContext, all, …) as
 * a remote-call surface, and the lifecycle names on top of that. Anything else
 * is refused rather than invoked.
 */
class ActionInvoker
{
    /** Lifecycle methods that are never callable as actions from the browser. */
    public const RESERVED_METHODS = [
        'render', 'mount', 'view', 'all', 'setproperty', 'setcontext',
        'getid', 'getname', 'boot', 'booted', 'hydrate', 'dehydrate',
        'updating', 'updated', 'rendering', 'rendered',
    ];

    public function __construct(protected Container $container) {}

    /** Invoke a browser-called action, rejecting anything not a public component method. */
    public function call(Component $component, string $method, array $params): void
    {
        if ($method === '' || ! $this->isCallable($component, $method)) {
            throw new RuntimeException(
                "Livewire method [{$method}] is not callable on component [{$component->getName()}]."
            );
        }

        try {
            $this->container->call([$component, $method], $params);
        } catch (ValidationException $exception) {
            // Validation failures are recorded on the component's error bag; swallow
            // so the component re-renders inline with errors instead of 500-ing.
        }
    }

    /** A callable action is a public, non-static method declared on the component subclass. */
    public function isCallable(Component $component, string $method): bool
    {
        if (! method_exists($component, $method) || in_array(strtolower($method), self::RESERVED_METHODS, true)) {
            return false;
        }

        $reflection = new ReflectionMethod($component, $method);

        return $reflection->isPublic()
            && ! $reflection->isStatic()
            && $reflection->getDeclaringClass()->getName() !== Component::class;
    }

    /**
     * The region an action's #[RenderRegion] attribute scopes the re-render to,
     * or null when it declares none.
     */
    public function renderRegionFor(Component $component, string $action): ?string
    {
        try {
            $method = new ReflectionMethod($component, $action);
        } catch (ReflectionException) {
            return null;
        }

        $attributes = $method->getAttributes(RenderRegion::class);

        return $attributes === [] ? null : $attributes[0]->newInstance()->name;
    }
}
