<?php

namespace Nitro\Console\Commands;

use Nitro\Console\ExitCode;
use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\PathRegistry;
use Nitro\Routing\RouteLoader;
use Nitro\Routing\Contracts\RouterInterface as Router;

/**
 * Console commands: cache (route:cache) and clear (route:clear) the compiled route table.
 */
class RouteCommands implements CommandInterface
{
    /**
     * The Container will auto-wire these using Reflection.
     */
    public function __construct(
        private readonly RouteLoader $routeLoader,
        private readonly Router $router,
        private readonly PathRegistry $paths,
        private readonly OutputFormatter $output
    ) {}

    /**
     * Signature => description, as a constant so the manager can read it
     * without constructing the command.
     *
     * @var array<string, string>
     */
    public const COMMANDS = [
            'route:cache' => 'Cache all routes for improved performance',
            'route:clear' => 'Clear route cache'
        ];

    /** How many blocking routes to name before summarising the rest. */
    private const CLOSURES_LISTED = 20;

    public function getCommands(): array
    {
        return self::COMMANDS;
    }

    public function handle(string $command, array $arguments): int
    {
        return match ($command) {
            'route:cache' => $this->cacheRoutes(),
            'route:clear' => $this->clearRoutes(),
            default       => $this->invalidSignature("Unknown route command: {$command}")
        };
    }

    protected function cacheRoutes(): int
    {
        $this->output->info("Caching routes...");

        try {
            $exitCode = $this->writeRouteCache();
        } catch (\Exception $exception) {
            $this->output->error("Error caching routes: " . $exception->getMessage());
            $exitCode = ExitCode::FAILURE;
        }

        return $exitCode;
    }

    /**
     * Reload the route files, compile them, and report what actually happened.
     *
     * {@see RouteLoader::cache()} returns the number of routes that stopped it
     * writing anything, which this used to discard — so an application whose
     * cache was refused was told "Routes cached successfully!", pointed at a
     * file that did not exist, and given exit code 0 to deploy on.
     */
    protected function writeRouteCache(): int
    {
        if (! $this->routeLoader->hasRouteFiles()) {
            $this->output->error("No route files found (routes/web.php, routes/api.php, or config/routes.php).");

            return ExitCode::FAILURE;
        }

        $this->router->clearRoutes();
        $this->routeLoader->loadFromFile($this->router);

        $routes = $this->router->getRoutes();

        if (empty($routes)) {
            $this->output->warning("No routes found to cache.");

            return ExitCode::SUCCESS;
        }

        if ($this->routeLoader->cache($this->router) > 0) {
            return $this->reportClosureRoutes($routes);
        }

        $this->output->success("Cached {$this->countRoutes($routes)} routes.");
        $this->output->success("Cache file: " . $this->paths->cachedRoutes());
        $this->output->writeln("");
        $this->output->writeln($this->output->color("========================================", 'green'));
        $this->output->writeln($this->output->color("Routes cached successfully!", 'green', true));
        $this->output->writeln($this->output->color("========================================", 'green'));

        return ExitCode::SUCCESS;
    }

    /**
     * Report the closure routes that stopped the cache from being written.
     *
     * Route caching is all-or-nothing: a closure cannot be written into a PHP
     * file, so one of them costs the application every route it has. Naming
     * them is the difference between a number and something to act on.
     */
    protected function reportClosureRoutes(array $routes): int
    {
        $closures = [];

        foreach ($routes as $method => $methodRoutes) {
            foreach ($methodRoutes as $path => $handler) {
                if (($handler['type'] ?? null) === 'closure') {
                    $closures[] = str_pad($method, 7) . $path;
                }
            }
        }

        $this->output->error("Routes were NOT cached: a closure handler cannot be compiled.");
        $this->output->writeln("");
        $this->output->writeln("Give these a controller or invokable-class handler instead:");

        foreach (array_slice($closures, 0, self::CLOSURES_LISTED) as $route) {
            $this->output->writeln("  {$route}");
        }

        if (count($closures) > self::CLOSURES_LISTED) {
            $this->output->writeln("  ... and " . (count($closures) - self::CLOSURES_LISTED) . " more");
        }

        return ExitCode::FAILURE;
    }

    /**
     * The number of registered routes — paths across every verb, not the nodes
     * of the nested array that holds them.
     */
    protected function countRoutes(array $routes): int
    {
        return array_sum(array_map('count', $routes));
    }

    protected function clearRoutes(): int
    {
        $this->output->info("Clearing route cache...");

        $exitCode = ExitCode::SUCCESS;

        try {
            if ($this->routeLoader->clearCache($this->router)) {
                $this->output->success("Route cache cleared.");
                $this->output->writeln("");
                $this->output->writeln($this->output->color("========================================", 'green'));
                $this->output->writeln($this->output->color("Route cache cleared!", 'green', true));
                $this->output->writeln($this->output->color("========================================", 'green'));
            } else {
                $this->output->warning("No route cache found to clear.");
            }
        } catch (\Exception $exception) {
            $this->output->error("Error clearing cache: " . $exception->getMessage());
            $exitCode = ExitCode::FAILURE;
        }

        return $exitCode;
    }
    /** Report an unrecognised signature and fail the invocation. */
    private function invalidSignature(string $message): int
    {
        $this->output->error($message);

        return ExitCode::INVALID;
    }
}
