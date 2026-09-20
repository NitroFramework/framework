<?php

namespace Nitro\Http\Controller\Concerns;

use Nitro\Auth\Access\Gate;
use Nitro\Auth\Exceptions\AuthorizationException;
use Nitro\Routing\Route;
use Nitro\Routing\Router;

/**
 * Checking an ability from inside an action, and stopping when it is denied.
 *
 * The Gate already answers the question; this is the short way to ask it where
 * a denial should end the request rather than return a boolean.
 *
 *     $this->authorize('update', $order);
 */
trait AuthorizesRequests
{
    /**
     * Stop unless the current user may do this.
     *
     * @param  mixed $arguments The model, or arguments the ability takes.
     * @throws AuthorizationException
     */
    protected function authorize(string $ability, mixed $arguments = []): void
    {
        $this->gate()->authorize($ability, $arguments);
    }

    /**
     * Stop unless the given user may do this.
     *
     * @throws AuthorizationException
     */
    protected function authorizeForUser(mixed $user, string $ability, mixed $arguments = []): void
    {
        if (! $this->gate()->forUser($user)->check($ability, $arguments)) {
            throw new AuthorizationException("This action is unauthorized: {$ability}.");
        }
    }

    /**
     * Whether the current user may do this, without stopping.
     */
    protected function can(string $ability, mixed $arguments = []): bool
    {
        return $this->gate()->allows($ability, $arguments);
    }

    /**
     * The inverse of {@see can()}, for the reading it makes at a call site.
     */
    protected function cannot(string $ability, mixed $arguments = []): bool
    {
        return $this->gate()->denies($ability, $arguments);
    }

    /**
     * Map every action of a resource controller to the ability it needs.
     *
     * Declared once in the constructor of a resource controller rather than
     * repeated as the first line of seven actions.
     *
     * @param  array<string, string> $abilities Action name to ability, merged over the defaults.
     * @throws AuthorizationException
     */
    protected function authorizeResource(string $model, ?string $parameter = null, array $abilities = []): void
    {
        $method = $this->currentAction();

        if ($method === null) {
            return;
        }

        $abilities += [
            'index'   => 'viewAny',
            'show'    => 'view',
            'create'  => 'create',
            'store'   => 'create',
            'edit'    => 'update',
            'update'  => 'update',
            'destroy' => 'delete',
        ];

        if (! isset($abilities[$method])) {
            return;
        }

        /*
         * The ones that act on a record are asked about that record; the rest
         * are asked about the class, because there is nothing yet to ask about.
         */
        $needsRecord = in_array($method, ['show', 'edit', 'update', 'destroy'], true);
        $subject     = $needsRecord ? ($this->routeParameter($parameter) ?? $model) : $model;

        $this->authorize($abilities[$method], $subject);
    }

    /**
     * The action currently running, taken from the matched route.
     */
    private function currentAction(): ?string
    {
        return $this->currentRoute()?->getControllerMethod();
    }

    /**
     * A bound parameter from the matched route, by name.
     */
    private function routeParameter(?string $parameter): mixed
    {
        if ($parameter === null) {
            return null;
        }

        return $this->currentRoute()?->getParameters()[$parameter] ?? null;
    }

    /**
     * The route being served, or null outside a request.
     */
    private function currentRoute(): ?Route
    {
        $container = app();

        return $container->has(Router::class) ? $container->resolve(Router::class)->current() : null;
    }

    private function gate(): Gate
    {
        return app()->resolve(Gate::class);
    }
}
