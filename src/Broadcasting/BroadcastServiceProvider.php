<?php

namespace Nitro\Broadcasting;

use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\Providers\ServiceProvider;
use Nitro\Routing\Router;

/**
 * Binds the broadcast manager and serves the channel-authorisation endpoint.
 *
 * No factory: both of the manager's dependencies are types the container can
 * resolve, so it autowires. It asks for a
 * {@see \Nitro\Container\Contracts\ClassResolver} rather than the container —
 * it instantiates channel authorisers named by class string, and that is the
 * only container capability it needs. Principle of least privilege.
 *
 * Not deferred, although it was. A deferred provider boots when something
 * resolves one of its services, and a route has to be registered before the
 * request is matched — so deferring it meant the authorisation endpoint did
 * not exist, and a private channel could not be joined at all. What deferring
 * actually saved is still saved: the manager is a lazy singleton and a driver
 * is only built when something broadcasts, so the cost here is one route and
 * one config read.
 */
class BroadcastServiceProvider extends ServiceProvider
{

    public function register(): void
    {
        $this->container->singleton(BroadcastManager::class);
    }

    /**
     * Serve the endpoint a client asks for permission at.
     *
     * Behind the 'web' group, so the session is loaded and CSRF verified: the
     * question being answered is "may this signed-in person listen here", and
     * without the session there is nobody to ask about.
     *
     * The path is configurable because an application already serving
     * something at /broadcasting has to be able to move it.
     */
    public function boot(): void
    {
        if (! $this->container->has(Router::class)) {
            return;
        }

        $config = $this->container->resolve(ConfigRepository::class);

        if ($config->get('broadcasting.routes', true) === false) {
            return;
        }

        $path = (string) $config->get('broadcasting.auth_path', '/broadcasting/auth');

        $router = $this->container->resolve(Router::class);

        $router->group(['middleware' => ['web']], static function () use ($router, $path): void {
            // The [class, method] form rather than the bare class string: the
            // router calls class_exists() on a string action to work out
            // whether it is a single-action class, and that loads the
            // controller at boot for every request that never authorises
            // anything.
            $router->post($path, [BroadcastController::class, '__invoke'])->name('broadcasting.auth');
        });
    }
}
