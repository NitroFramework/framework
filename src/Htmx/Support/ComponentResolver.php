<?php

namespace Nitro\Htmx\Support;

use Nitro\Container\Container;
use Nitro\Exceptions\HttpException;
use Nitro\Htmx\HtmxComponent;
use ReflectionMethod;

/**
 * Resolves HTMX component instances from short names and validates
 * that requested actions are safe to call.
 *
 * Used by HtmxKernel to map incoming request parameters (component name,
 * action name) to actual class instances and callable methods.
 */
class ComponentResolver
{
    /** Lifecycle hooks that cannot be invoked as actions from the client */
    private const RESERVED_ACTIONS = [
        'onBoot',
        'onMount',
        'onBeforeAction',
        'onAfterAction',
    ];

    public function __construct(
        private Container $container,
        private string $componentNamespace,
    ) {}

    /**
     * Resolve a component short name to a fully instantiated HtmxComponent.
     *
     * @param  string $component  Short component name (e.g. 'Counter', 'TodoList')
     * @return HtmxComponent
     * @throws HttpException 404 if the class doesn't exist or isn't an HtmxComponent
     */
    public function resolve(string $component): HtmxComponent
    {
        $class = $this->componentNamespace . ucfirst($component);

        if (!class_exists($class) || !is_subclass_of($class, HtmxComponent::class)) {
            throw new HttpException(404, "HTMX Component [{$component}] not found.");
        }

        return $this->container->make($class);
    }

    /**
     * Assert that an action is safe and callable on the given component.
     *
     * @param  HtmxComponent $instance
     * @param  string        $action
     * @throws HttpException 403/404 if any check fails
     */
    public function assertCallable(HtmxComponent $instance, string $action): void
    {
        if (in_array($action, self::RESERVED_ACTIONS, true)) {
            throw new HttpException(403, "Action [{$action}] is reserved.");
        }

        // Property-update hooks (onUpdating*/onUpdated*) are framework-invoked
        // during hydration only — never callable directly from the client, or a
        // forged request could fire a component's update reactions out of band.
        if (str_starts_with($action, 'onUpdating') || str_starts_with($action, 'onUpdated')) {
            throw new HttpException(403, "Action [{$action}] is a reserved lifecycle hook.");
        }

        if (!method_exists($instance, $action)) {
            throw new HttpException(404, "Action [{$action}] not found on " . get_class($instance));
        }

        $method = new ReflectionMethod($instance, $action);

        if (!$method->isPublic()) {
            throw new HttpException(403, "Action [{$action}] is not public.");
        }

        // index() ships on the base class so components don't have to declare
        // it; everything else inherited from HtmxComponent is framework
        // internals and must not be callable as an action.
        if ($method->getDeclaringClass()->getName() === HtmxComponent::class && $action !== 'index') {
            throw new HttpException(403, "Action [{$action}] is not callable.");
        }
    }
}