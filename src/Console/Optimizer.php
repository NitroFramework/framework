<?php

namespace Nitro\Console;

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Nitro\Foundation\Application;
use Nitro\Routing\RouteCompiler;

/**
 * Builds Nitro's caches from a fresh, uncached boot of the app (bootstrap/app.php), the same way
 * Laravel's config:cache / route:cache do, so package providers have registered and booted and
 * their routes are part of the compiled table.
 */
final class Optimizer
{
    public function __construct(private readonly Application $app)
    {
    }

    public function fresh(bool $profile = false): Application
    {
        $fresh = require $this->app->bootstrapPath('app.php');
        $fresh->ignoreCaches();

        if ($profile) {
            $fresh->enableProviderProfiling();
        }

        $fresh->make(ConsoleKernel::class)->bootstrap();

        return $fresh;
    }

    public function restore(): void
    {
        Container::setInstance($this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
    }

    /**
     * Compile routes (bootstrap/cache/routes-v7.php) and the container factories for every class
     * those routes build (bootstrap/cache/factories.php). Returns [route table, factory compiler].
     */
    public function cacheRoutes(Application $fresh): array
    {
        $router = $fresh->make('router');
        $table = RouteCompiler::compile($router->getRoutes(), $router, forCache: true);
        $files = new Filesystem;

        $files->replace($this->app->getCachedRoutesPath(), RouteCompiler::export($table));

        $compiler = new FactoryCompiler($fresh);
        $files->replace($this->app->getCachedFactoriesPath(), $compiler->compile($this->hotClasses($fresh, $table)));

        return [$table, $compiler];
    }

    /**
     * Classes built on requests: controllers, middleware (route + global) and injected arguments.
     *
     * @return list<string>
     */
    public function hotClasses(Application $fresh, array $table): array
    {
        $classes = [];

        foreach ($fresh->make(\Illuminate\Contracts\Http\Kernel::class)->getGlobalMiddleware() as $middleware) {
            if (is_string($middleware)) {
                $classes[explode(':', $middleware, 2)[0]] = true;
            }
        }

        foreach ($table['routes'] as $entry) {
            if ($entry['controller'] !== null) {
                $classes[$entry['controller'][0]] = true;
            }

            foreach ($entry['middleware'] as [$middleware, , $isClosure]) {
                if (! $isClosure) {
                    $classes[$middleware] = true;
                }
            }

            foreach ($entry['plan'] ?? [] as [$class, , , $isEnum]) {
                if ($class !== null && ! $isEnum) {
                    $classes[$class] = true;
                }
            }
        }

        return array_keys($classes);
    }
}
