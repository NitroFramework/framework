<?php

namespace Nitro\View\Support;

use Closure;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Events\Contracts\Dispatcher;
use Nitro\Support\Str;
use Nitro\View\Contracts\ViewComposerResolver;
use Nitro\View\View;

/**
 * View composers and creators, carried on the event bus.
 *
 *     View::composer('orders.*', OrderComposer::class);
 *     View::creator('profile', fn (View $view) => $view->with('user', auth()->user()));
 *
 * A composer registered for 'orders.show' listens for "composing: orders.show",
 * and a creator for "creating: orders.show". A creator runs when the view is
 * made; a composer runs just before it renders, after the caller has had the
 * chance to add its own data. Because both are ordinary listeners, a pattern
 * matches the way any wildcard event does ('admin.*', '*.index', '*'), and one
 * registered straight on the bus with Event::listen() fires the same way.
 *
 * A composer named by class is built through the container when it fires, and
 * its compose() is called; 'Class@method' names another method. A creator's
 * default method is create().
 */
class ComposerResolver implements ViewComposerResolver
{
    public function __construct(
        private Dispatcher $events,
        private ClassResolver $resolver,
    ) {
    }

    public function composer(array|string $views, callable|string $callback): array
    {
        $composers = [];

        foreach ((array) $views as $view) {
            $composers[] = $this->addViewEvent($view, $callback);
        }

        return $composers;
    }

    public function composers(array $composers): array
    {
        $registered = [];

        foreach ($composers as $callback => $views) {
            $registered = array_merge($registered, $this->composer($views, $callback));
        }

        return $registered;
    }

    public function creator(array|string $views, callable|string $callback): array
    {
        $creators = [];

        foreach ((array) $views as $view) {
            $creators[] = $this->addViewEvent($view, $callback, 'creating: ');
        }

        return $creators;
    }

    public function callComposer(View $view): void
    {
        if ($this->events->hasListeners($event = 'composing: ' . $this->normalizeName($view->name()))) {
            $this->events->dispatch($event, [$view]);
        }
    }

    public function callCreator(View $view): void
    {
        if ($this->events->hasListeners($event = 'creating: ' . $this->normalizeName($view->name()))) {
            $this->events->dispatch($event, [$view]);
        }
    }

    /**
     * Asked before a nested view is wrapped in a View object, so a page whose
     * partials nobody composes builds none.
     */
    public function hasViewListeners(string $view): bool
    {
        $name = $this->normalizeName($view);

        return $this->events->hasListeners('creating: ' . $name)
            || $this->events->hasListeners('composing: ' . $name);
    }

    /**
     * Listen for one view event, as a closure or a class.
     */
    private function addViewEvent(string $view, callable|string $callback, string $prefix = 'composing: '): Closure
    {
        $view = $this->normalizeName($view);

        $callback = is_string($callback)
            ? $this->buildClassEventCallback($callback, $prefix)
            : Closure::fromCallable($callback);

        $this->addEventListener($prefix . $view, $callback);

        return $callback;
    }

    /**
     * The closure that builds a composer or creator class and calls it.
     *
     * Built when the event fires rather than when registered, so a composer
     * for a view that never renders is never constructed.
     */
    private function buildClassEventCallback(string $class, string $prefix): Closure
    {
        [$class, $method] = Str::parseCallback($class, str_contains($prefix, 'composing') ? 'compose' : 'create');

        return function (...$arguments) use ($class, $method): mixed {
            return $this->resolver->resolve($class)->{$method}(...$arguments);
        };
    }

    /**
     * Register the listener on the bus.
     *
     * A wildcard listener is handed the event name and the payload rather than
     * the payload spread, so it is wrapped to receive the view like any other.
     */
    private function addEventListener(string $name, Closure $callback): void
    {
        if (str_contains($name, '*')) {
            $callback = static function (string $name, array $data) use ($callback): mixed {
                return $callback($data[0]);
            };
        }

        $this->events->listen($name, $callback);
    }

    /**
     * A view name as its events are keyed: slashes read as dots, and a
     * namespace kept apart so its '::' is not touched.
     */
    private function normalizeName(string $name): string
    {
        if (! str_contains($name, '::')) {
            return str_replace('/', '.', $name);
        }

        [$namespace, $name] = explode('::', $name, 2);

        return $namespace . '::' . str_replace('/', '.', $name);
    }
}
