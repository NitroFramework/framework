<?php

namespace Nitro\Foundation;

use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Bootstrap\SetRequestForConsole;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Laravel\SerializableClosure\SerializableClosure;
use Nitro\Components\Core;
use Nitro\Components\Database;

/**
 * Nitro's bootstrap step, run between Laravel's RegisterFacades and RegisterProviders:
 *
 *  - compiled container factories (bootstrap/cache/factories.php)
 *  - Eloquent's connection resolver, the closure signing key and queued-job context, wired when
 *    first used
 *  - the HTTP kernel's middleware synced to the router before providers boot, so packages can
 *    extend groups (and the route compiler sees resolved stacks even in console)
 *
 * Everything else is Laravel's own bootstrappers, so .env, config (with the framework's default
 * config merged in), error handling, facades and providers behave exactly as in Laravel.
 */
final class Bootstrap
{
    /** @return list<class-string> */
    public static function bootstrappers(bool $console = false): array
    {
        return array_values(array_filter([
            LoadEnvironmentVariables::class,
            LoadConfiguration::class,
            HandleExceptions::class,
            RegisterFacades::class,
            $console ? SetRequestForConsole::class : null,
            self::class,
            RegisterProviders::class,
            BootProviders::class,
        ]));
    }

    public function bootstrap(ApplicationContract $app): void
    {
        if (is_file($path = $app->getCachedFactoriesPath())) {
            [$factories, $map] = require $path;

            $app->setCompiledFactories($factories, $map);
        }

        /** Laravel does these eagerly; here each happens when its class is first used. */
        $appConfig = $app['config']->get('app', []);
        ClassLoadHooks::after(SerializableClosure::class, static fn () => Core::configureSerializableClosure($appConfig));
        ClassLoadHooks::after(Model::class, static fn () => Database::bootEloquent($app));
        ClassLoadHooks::after(Queue::class, static fn () => Core::carryContextInPayloads());
        ClassLoadHooks::after(JobProcessing::class, static fn () => Core::hydrateContextForJobs($app));

        $kernel = $app->make(Kernel::class);

        if (method_exists($kernel, 'syncMiddlewareToRouter')) {
            $kernel->syncMiddlewareToRouter();
        }
    }
}
