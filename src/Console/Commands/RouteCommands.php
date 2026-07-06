<?php

namespace Nitro\Console\Commands;

use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\PathRegistry;
use Nitro\Routing\RouteLoader;
use Nitro\Routing\Router;

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

    public function getCommands(): array
    {
        return [
            'route:cache' => 'Cache all routes for improved performance',
            'route:clear' => 'Clear route cache'
        ];
    }

    public function handle(string $command, array $arguments): void
    {
        match ($command) {
            'route:cache' => $this->cacheRoutes(),
            'route:clear' => $this->clearRoutes(),
            default       => $this->output->error("Unknown route command: {$command}")
        };
    }

    protected function cacheRoutes(): void
    {
        $this->output->info("Caching routes...");

        try {
            if (!$this->routeLoader->hasRouteFiles()) {
                $this->output->error("No route files found (routes/web.php, routes/api.php, or config/routes.php).");
                return;
            }

            $this->router->clearRoutes();
            $this->routeLoader->loadFromFile($this->router);

            $routes = $this->router->getRoutes();
            if (empty($routes)) {
                $this->output->warning("No routes found to cache.");
                return;
            }

            $this->routeLoader->cache($this->router);
            $routeCount = count($routes, COUNT_RECURSIVE);
            $cacheFile  = $this->paths->cache('routes.php');

            $this->output->success("Cached {$routeCount} routes.");
            $this->output->success("Cache file: " . basename($cacheFile));
            $this->output->writeln("");
            $this->output->writeln($this->output->color("========================================", 'green'));
            $this->output->writeln($this->output->color("Routes cached successfully!", 'green', true));
            $this->output->writeln($this->output->color("========================================", 'green'));
        } catch (\Exception $e) {
            $this->output->error("Error caching routes: " . $e->getMessage());
        }
    }

    protected function clearRoutes(): void
    {
        $this->output->info("Clearing route cache...");

        try {
            // Straightforward property access
            $wasCleared = $this->routeLoader->clearCache($this->router);

            if ($wasCleared) {
                $this->output->success("Route cache cleared.");
            } else {
                $this->output->warning("No route cache found to clear.");
            }

            $this->output->writeln("");
            $this->output->writeln($this->output->color("========================================", 'green'));
            $this->output->writeln($this->output->color("Route cache cleared!", 'green', true));
            $this->output->writeln($this->output->color("========================================", 'green'));
        } catch (\Exception $e) {
            $this->output->error("Error clearing cache: " . $e->getMessage());
        }
    }
}
