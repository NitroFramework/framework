<?php

namespace Nitro\Foundation;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Http\Kernel as BaseKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Nitro\Routing\Pipeline;
use Nitro\Routing\Router;
use Throwable;

/**
 * Laravel's HTTP kernel (every public method, the middleware API used by withMiddleware(),
 * lifecycle hooks) with Nitro's request path:
 *
 *  - Nitro's bootstrap sequence (Bootstrap::bootstrappers()).
 *  - Global middleware through Nitro's pipeline (no closure onion built up front).
 *  - The router dispatches from the compiled route table.
 *  - terminate() calls terminate() on the middleware instances that actually ran, instead of
 *    re-resolving and re-sorting the route's middleware.
 */
class HttpKernel extends BaseKernel
{
    /** Parsed global middleware stack, rebuilt when the list changes. */
    protected ?array $globalStack = null;

    protected ?array $globalStackSource = null;

    /** @var list<Pipeline> Pipelines of the current request, for terminate(). */
    protected array $pipelines = [];

    protected function bootstrappers()
    {
        return Bootstrap::bootstrappers();
    }

    public function handle($request)
    {
        if ($this->requestLifecycleDurationHandlers !== []) {
            $this->requestStartedAt = Carbon::now();
        }

        $this->pipelines = [];

        try {
            $request->enableHttpMethodParameterOverride();

            $response = $this->sendRequestThroughRouter($request);
        } catch (Throwable $e) {
            $this->reportException($e);

            $response = $this->renderException($request, $e);
        }

        $events = $this->app['events'];

        if ($events->hasListeners(RequestHandled::class)) {
            $events->dispatch(new RequestHandled($request, $response));
        }

        return $response;
    }

    protected function sendRequestThroughRouter($request)
    {
        $this->app->instance('request', $request);

        Facade::clearResolvedInstance('request');

        $this->bootstrap();

        /** AuthServiceProvider's request rebinding: $request->user() goes through the auth manager. */
        $app = $this->app;
        $request->setUserResolver(static fn ($guard = null) => call_user_func($app['auth']->userResolver(), $guard));

        if ($this->app->shouldSkipMiddleware() || $this->middleware === []) {
            $response = $this->router->dispatch($request);
        } else {
            if ($this->globalStackSource !== $this->middleware) {
                $this->globalStack = Pipeline::parse($this->middleware);
                $this->globalStackSource = $this->middleware;
            }

            $pipeline = $this->pipelines[] = new Pipeline($this->app);
            $response = $pipeline->run($this->globalStack, $request, $this->dispatchToRouter());
        }

        if ($this->router instanceof Router && ($routePipeline = $this->router->nitroDispatcher()->lastPipeline)) {
            $this->pipelines[] = $routePipeline;
            $this->router->nitroDispatcher()->lastPipeline = null;
        }

        return $response;
    }

    protected function terminateMiddleware($request, $response)
    {
        foreach ($this->pipelines as $pipeline) {
            foreach ($pipeline->used as $middleware) {
                if (method_exists($middleware, 'terminate')) {
                    $middleware->terminate($request, $response);
                }
            }
        }

        $this->pipelines = [];
    }

    /**
     * Push the kernel's groups, aliases and priority into the router. Public so bootstrap can
     * run it before providers boot (packages then extend the groups, as in Laravel).
     */
    public function syncMiddlewareToRouter()
    {
        parent::syncMiddlewareToRouter();
    }
}
