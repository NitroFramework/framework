<?php

namespace Nitro\Inertia;

use Nitro\Http\Kernel;
use Nitro\Http\Request;
use Nitro\Http\Response as HttpResponse;
use Nitro\Inertia\Props\OnceProp;
use Throwable;

/**
 * An error, rendered as a page of the application rather than as a server
 * error document.
 *
 * Without this, a 500 in an Inertia app replaces the running application with
 * a plain HTML error page: the client is unmounted, and a user who navigates
 * back is doing a full page load. Rendering the error as a component keeps
 * them inside the app.
 *
 * Offered to the callback given to {@see ResponseFactory::handleExceptionsUsing()},
 * which decides — usually by status — whether an exception is worth a page at
 * all. Left alone, it hands back the response it was given.
 */
class ExceptionResponse
{
    private ?string $component = null;

    /** @var array<string, mixed> */
    private array $props = [];

    private bool $includeSharedData = false;

    private ?string $rootView = null;

    /** @var class-string<Middleware>|null */
    private ?string $middlewareClass = null;

    public function __construct(
        public readonly Throwable $exception,
        public readonly Request $request,
        public readonly HttpResponse $response,
    ) {
    }

    /**
     * Render this exception as a component.
     *
     * @param array<string, mixed> $props
     */
    public function render(string $component, array $props = []): static
    {
        $this->component = $component;
        $this->props = $props;

        return $this;
    }

    /**
     * Name the Inertia middleware to take settings from.
     *
     * Only needed when it cannot be found from the route or the middleware
     * groups — an error raised before routing, for instance.
     *
     * @param class-string<Middleware> $middlewareClass
     */
    public function usingMiddleware(string $middlewareClass): static
    {
        $this->middlewareClass = $middlewareClass;

        return $this;
    }

    /**
     * Include the application's shared props on the error page.
     *
     * Off by default: shared props are resolved by closures that assume a
     * normal request, and running them while handling a failure can fail
     * again. Worth turning on when the error page needs the layout's data —
     * the signed-in user, say.
     */
    public function withSharedData(): static
    {
        $this->includeSharedData = true;

        return $this;
    }

    public function rootView(string $rootView): static
    {
        $this->rootView = $rootView;

        return $this;
    }

    public function statusCode(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * The response to send: the error page, or the original response when no
     * component was named.
     */
    public function toResponse(): HttpResponse
    {
        if ($this->component === null) {
            return $this->response;
        }

        $factory = app(ResponseFactory::class);
        $middleware = $this->resolveMiddleware();

        if ($middleware !== null) {
            $factory->version(fn (): ?string => $middleware->version($this->request));
            $factory->setRootView($this->rootView ?? $middleware->rootView($this->request));
        } elseif ($this->rootView !== null) {
            $factory->setRootView($this->rootView);
        }

        if ($this->includeSharedData && $middleware !== null) {
            $factory->share($middleware->share($this->request));

            foreach ($middleware->shareOnce($this->request) as $key => $value) {
                if ($value instanceof OnceProp) {
                    $factory->share($key, $value);
                } else {
                    $factory->shareOnce($key, $value);
                }
            }
        }

        return $factory->render($this->component, $this->props)
            ->toResponse($this->request)
            ->setStatusCode($this->response->getStatusCode());
    }

    private function resolveMiddleware(): ?Middleware
    {
        if ($this->middlewareClass !== null) {
            return app($this->middlewareClass);
        }

        $class = $this->middlewareFromRoute() ?? $this->middlewareFromKernel();

        return $class === null ? null : app($class);
    }

    /**
     * The Inertia middleware the matched route runs under.
     *
     * @return class-string<Middleware>|null
     */
    private function middlewareFromRoute(): ?string
    {
        $route = $this->request->route();

        /*
         * Checked by capability, the way the rest of the framework reads a
         * route off a request: route() is typed mixed and returns null before
         * routing has run, which is exactly when an early failure lands here.
         */
        if ($route === null || ! method_exists($route, 'getMiddleware')) {
            return null;
        }

        foreach ((array) $route->getMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            // A middleware may carry parameters after a colon; the class is
            // what comes before.
            $class = explode(':', $middleware)[0];

            if (is_a($class, Middleware::class, true)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * The first Inertia middleware in any group, for an error raised before a
     * route was matched.
     *
     * @return class-string<Middleware>|null
     */
    private function middlewareFromKernel(): ?string
    {
        /*
         * Guarded because this runs while a failure is being handled. If the
         * kernel is not resolvable — an error raised before the application
         * finished coming up — that is another failure, and raising it here
         * would replace the one being reported.
         */
        try {
            $groups = app(Kernel::class)->getMiddlewareGroups();
        } catch (Throwable) {
            return null;
        }

        foreach ($groups as $group) {
            foreach ((array) $group as $middleware) {
                if (is_string($middleware) && is_a($middleware, Middleware::class, true)) {
                    return $middleware;
                }
            }
        }

        return null;
    }
}
