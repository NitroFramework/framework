<?php

namespace Nitro\Components;

use Illuminate\Auth\Access\Gate;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Auth\Passwords\PasswordBrokerManager;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Contracts\Routing\UrlGenerator as UrlGeneratorContract;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Response;
use Illuminate\Routing\CallableDispatcher;
use Illuminate\Routing\ControllerDispatcher;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Nitro\Foundation\Application;
use Nitro\Routing\CompiledRoutes;
use Nitro\Routing\Router;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/**
 * Routing, URL generation, session and auth. Mirrors RoutingServiceProvider,
 * SessionServiceProvider and AuthServiceProvider without registering anything up front.
 */
final class Http
{
    public static function router(Application $app): Router
    {
        $router = new Router($app->make('events'), $app);

        if ($app->routesAreCached()) {
            $router->useCompiledRoutes(CompiledRoutes::fromFile($app->getCachedRoutesPath(), $router));
        }

        return $router;
    }

    public static function url(Application $app): UrlGenerator
    {
        $routes = $app->make('router')->getRoutes();

        $app->instance('routes', $routes);

        $url = new UrlGenerator(
            $routes,
            $app->rebinding('request', static function ($app, $request) {
                $app['url']->setRequest($request);
            }),
            $app->make('config')->get('app.asset_url')
        );

        $url->setSessionResolver(static fn () => $app->bound('session') ? $app->make('session') : null);

        $url->setKeyResolver(static function () use ($app) {
            $config = $app->make('config');

            return [$config->get('app.key'), ...($config->get('app.previous_keys') ?? [])];
        });

        $app->rebinding('routes', static function ($app, $routes) {
            $app['url']->setRoutes($routes);
        });

        return $url;
    }

    public static function redirect(Application $app): Redirector
    {
        $redirector = new Redirector($app->make('url'));

        if ($app->bound('session.store') && $app->make('config')->get('session.driver')) {
            $redirector->setSession($app->make('session.store'));
        }

        return $redirector;
    }

    public static function responseFactory(Application $app): ResponseFactory
    {
        return new ResponseFactory($app->make(ViewFactory::class), $app->make('redirect'));
    }

    public static function callableDispatcher(Application $app): CallableDispatcher
    {
        return new CallableDispatcher($app);
    }

    public static function controllerDispatcher(Application $app): ControllerDispatcher
    {
        return new ControllerDispatcher($app);
    }

    public static function psrRequest(Application $app): mixed
    {
        if (! class_exists(PsrHttpFactory::class)) {
            throw new BindingResolutionException('Unable to resolve PSR request. Please install the "symfony/psr-http-message-bridge" package.');
        }

        $illuminateRequest = $app->make('request');
        $request = (new PsrHttpFactory)->createRequest($illuminateRequest);

        if ($illuminateRequest->getContentTypeFormat() !== 'json' && $illuminateRequest->request->count() === 0) {
            return $request;
        }

        return $request->withParsedBody(array_merge($request->getParsedBody() ?? [], $illuminateRequest->getPayload()->all()));
    }

    public static function psrResponse(): mixed
    {
        if (! class_exists(PsrHttpFactory::class)) {
            throw new BindingResolutionException('Unable to resolve PSR response. Please install the "symfony/psr-http-message-bridge" package.');
        }

        return (new PsrHttpFactory)->createResponse(new Response);
    }

    public static function requirePassword(Application $app): RequirePassword
    {
        return new RequirePassword(
            $app->make(ResponseFactoryContract::class),
            $app->make(UrlGeneratorContract::class),
            $app->make('config')->get('auth.password_timeout')
        );
    }

    public static function session(Application $app): SessionManager
    {
        return new SessionManager($app);
    }

    public static function sessionStore(Application $app): mixed
    {
        return $app->make('session')->driver();
    }

    public static function startSession(Application $app): StartSession
    {
        return new StartSession($app->make(SessionManager::class), static fn () => $app->make(CacheFactory::class));
    }

    public static function auth(Application $app): AuthManager
    {
        $auth = new AuthManager($app);

        /** AuthServiceProvider::registerEventRebindHandler() */
        $app->rebinding('events', static function ($app, $dispatcher) {
            if ($app['auth']->hasResolvedGuards() && method_exists($guard = $app['auth']->guard(), 'setDispatcher')) {
                $guard->setDispatcher($dispatcher);
            }
        });

        return $auth;
    }

    public static function authDriver(Application $app): mixed
    {
        return $app->make('auth')->guard();
    }

    public static function user(Application $app): mixed
    {
        return call_user_func($app->make('auth')->userResolver());
    }

    public static function gate(Application $app): Gate
    {
        return new Gate($app, static fn () => call_user_func($app->make('auth')->userResolver()));
    }

    public static function passwordBrokerManager(Application $app): PasswordBrokerManager
    {
        return new PasswordBrokerManager($app);
    }

    public static function passwordBroker(Application $app): mixed
    {
        return $app->make('auth.password')->broker();
    }
}
