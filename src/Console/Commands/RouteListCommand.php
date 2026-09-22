<?php

namespace Nitro\Console\Commands;

use Nitro\Console\ExitCode;
use Closure;
use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Console\Support\Terminal;
use Nitro\Foundation\Contracts\PathRegistry;
use Nitro\Routing\RouteLoader;
use Nitro\Routing\Contracts\RouterInterface as Router;

/**
 * Print the application's route table.
 *
 * The counterpart to `lifecycle`: that answers what runs per request, this
 * answers what can be reached. Between them there is no part of the request
 * path you have to read the source to discover.
 *
 * Routes are read from the loader, not from the cache, so the listing reflects
 * the route files as they are now rather than whatever was compiled last — a
 * stale cache is exactly the condition you would be running this to diagnose.
 *
 * Filters, all combinable:
 *
 *     nitro route:list --method=GET
 *     nitro route:list --path=courses
 *     nitro route:list --name=admin.
 *     nitro route:list --middleware=auth
 *     nitro route:list --except-vendor
 */
class RouteListCommand implements CommandInterface
{
    public function __construct(
        private readonly RouteLoader $routeLoader,
        private readonly Router $router,
        private readonly PathRegistry $paths,
        private readonly OutputFormatter $output,
    ) {}

    /**
     * Signature => description, as a constant so the manager can read it
     * without constructing the command.
     *
     * @var array<string, string>
     */
    public const COMMANDS = [
            'route:list' => 'List the application\'s routes (--method --path --name --middleware --except-vendor)',
        ];

    public function getCommands(): array
    {
        return self::COMMANDS;
    }

    public function handle(string $command, array $arguments = []): int
    {
        // Not cleared first, unlike route:cache: providers register routes of
        // their own during boot (/livewire/update, the asset routes), and
        // clearing would drop exactly the ones --except-vendor exists to hide.
        // Routes are keyed by method and path, so loading the files over the
        // top of them replaces rather than duplicates.
        $this->routeLoader->loadFromFile($this->router);

        $rows = $this->rows($this->filters($arguments));

        if ($rows === []) {
            $this->output->warning('No routes matched.');
            return ExitCode::FAILURE;
        }

        $this->render($rows);

        $this->output->writeln('');
        $this->output->info('  ' . count($rows) . ' route(s)');

        return ExitCode::SUCCESS;
    }

    /** @return array<string, string> */
    private function filters(array $arguments): array
    {
        $filters = [];

        foreach ($arguments as $argument) {
            if (preg_match('/^--(method|path|name|middleware)=(.*)$/', (string) $argument, $matches)) {
                $filters[$matches[1]] = $matches[2];
            }

            if ($argument === '--except-vendor') {
                $filters['except-vendor'] = '1';
            }
        }

        return $filters;
    }

    /**
     * Flatten the router's method => path => data structure into printable rows.
     *
     * @return array<int, array{method: string, uri: string, name: string, action: string, middleware: string}>
     */
    private function rows(array $filters): array
    {
        // The named-route registry is keyed by name; invert it so a row can
        // find its own name by method and path.
        $names = [];
        foreach ($this->router->getNamedRoutes() as $name => $route) {
            $names[$route['method'] . ' ' . $route['path']] = $name;
        }

        $rows = [];

        foreach ($this->router->getRoutes() as $method => $paths) {
            foreach ($paths as $path => $data) {
                $action = $this->describeAction($data);
                $middleware = implode(', ', (array) ($data['middleware'] ?? []));
                $name = $names[$method . ' ' . $path] ?? '';

                if (! $this->matches($filters, $method, $path, $name, $middleware, $action)) {
                    continue;
                }

                if (isset($filters['except-vendor']) && $this->isVendor($data)) {
                    continue;
                }

                $rows[] = [
                    'method' => $method,
                    'uri' => $path,
                    'name' => $name,
                    'action' => $action,
                    'middleware' => $middleware,
                ];
            }
        }

        // Sorted by path so related routes sit together, which is how anybody
        // reading this is actually looking for them — not by registration order.
        usort($rows, static fn (array $a, array $b): int => [$a['uri'], $a['method']] <=> [$b['uri'], $b['method']]);

        return $rows;
    }

    private function matches(array $filters, string $method, string $path, string $name, string $middleware, string $action): bool
    {
        if (isset($filters['method']) && strcasecmp($filters['method'], $method) !== 0) {
            return false;
        }

        if (isset($filters['path']) && ! str_contains($path, $filters['path'])) {
            return false;
        }

        if (isset($filters['name']) && ! str_contains($name, $filters['name'])) {
            return false;
        }

        if (isset($filters['middleware']) && ! str_contains($middleware, $filters['middleware'])) {
            return false;
        }

        return true;
    }

    /**
     * Whether a route was registered by the framework or a package rather than
     * by this application — /livewire/update and friends.
     *
     * Judged by where the code lives, not by its class name: the routes worth
     * hiding are mostly closures inside a provider, so a Nitro\ prefix check
     * would match none of them. Anything defined outside the application's own
     * directory, or inside its vendor/, is somebody else's — which also gets it
     * right when the framework is symlinked in from a path repository during
     * development and therefore sits outside vendor/ entirely.
     */
    private function isVendor(array $data): bool
    {
        $file = $this->sourceFile($data);

        if ($file === null) {
            return false;
        }

        $base = rtrim($this->paths->base(), '/\\');
        $file = str_replace('\\', '/', $file);
        $base = str_replace('\\', '/', $base);

        return ! str_starts_with($file, $base . '/')
            || str_starts_with($file, $base . '/vendor/');
    }

    /** The file a route's handler is defined in, where that is knowable. */
    private function sourceFile(array $data): ?string
    {
        try {
            $handler = $data['handler'] ?? null;

            if ($handler instanceof Closure) {
                return (new \ReflectionFunction($handler))->getFileName() ?: null;
            }

            $controller = $data['controller'] ?? null;

            if (is_string($controller) && class_exists($controller)) {
                return (new \ReflectionClass($controller))->getFileName() ?: null;
            }
        } catch (\ReflectionException) {
            return null;
        }

        return null;
    }

    /** A one-line description of what a route dispatches to. */
    private function describeAction(array $data): string
    {
        $type = $data['type'] ?? 'unknown';

        return match ($type) {
            'controller' => ($data['controller'] ?? '?') . '@' . ($data['method'] ?? '?'),
            'view' => 'view: ' . ($data['data']['view'] ?? $data['view'] ?? '?'),
            'closure', 'callable' => $this->describeClosure($data['handler'] ?? null),
            default => $type,
        };
    }

    /**
     * Where a closure route was defined.
     *
     * A bare "Closure" tells you nothing when six routes are closures; the file
     * and line is the only thing that distinguishes them, and it is what you
     * need in order to go and read it.
     */
    private function describeClosure(mixed $handler): string
    {
        if (! $handler instanceof Closure) {
            return is_string($handler) ? $handler : 'Closure';
        }

        try {
            $reflection = new \ReflectionFunction($handler);
            $file = $reflection->getFileName();

            if ($file === false) {
                return 'Closure';
            }

            return 'Closure at ' . basename($file) . ':' . $reflection->getStartLine();
        } catch (\ReflectionException) {
            return 'Closure';
        }
    }

    /** @param array<int, array<string, string>> $rows */
    /**
     * One route per line, the verb and path on the left and what handles it on
     * the right, joined by dots.
     *
     * Columns padded to the widest value pushed every action into the same far
     * column, so a list with one long path left a gap across every other row
     * and the eye had nothing to follow. Dots run from each path to its own
     * handler instead, which is the shape that stays readable however wide the
     * paths are.
     */
    private function render(array $rows): int
    {
        $method = $this->widest($rows, 'method', 3);
        $width = Terminal::width();

        $this->output->writeln('');

        foreach ($rows as $row) {
            $left = str_pad($row['method'], $method) . '  ' . $row['uri'];

            $right = $row['name'] !== ''
                ? $row['name'] . ' › ' . $row['action']
                : $row['action'];

            $this->output->writeln($this->leader($left, $right, $width, $row));

            if ($row['middleware'] !== '') {
                $this->output->writeln(
                    str_repeat(' ', $method + 4)
                    . $this->output->color('⇂ ' . $row['middleware'], 'gray')
                );
            }
        }

        return ExitCode::SUCCESS;
    }

    /**
     * A line of the form "  GET  /path ....... name › action".
     *
     * Colour is applied after the dots are measured: escape sequences have a
     * length that does not appear on screen, so counting them would shorten
     * every run of dots by exactly the amount of styling on the line.
     */
    private function leader(string $left, string $right, int $width, array $row): string
    {
        /*
         * Shortened in two steps when the line will not fit. The route name
         * goes first, because the handler is what someone reading this list is
         * looking for; only if the handler alone still overflows is it trimmed,
         * and then from the front, so a deeply namespaced controller keeps the
         * class and method rather than the namespace it shares with every other
         * row.
         */
        $room = $width - mb_strlen($left) - 6;

        /*
         * A path long enough to fill the line on its own leaves no room for
         * the handler, and giving up there simply let the row run past the
         * edge. The path is shortened instead, from the front, since its tail
         * is the part that distinguishes it from its neighbours.
         */
        if ($room < 12) {
            $keep = max(8, $width - 18);
            $left = mb_substr($left, 0, $keep - 1) . '…';
            $room = $width - mb_strlen($left) - 6;
        }

        if ($room > 0 && mb_strlen($right) > $room) {
            $row['name'] = '';
            $right = $row['action'];

            if (mb_strlen($right) > $room) {
                $right = '…' . mb_substr($right, -($room - 1));
                $row['action'] = $right;
            }
        }

        $plain = '  ' . $left . ' ' . $right;
        $dots = max(1, $width - mb_strlen($plain) - 2);

        $colouredLeft = $this->output->color(
            str_pad($row['method'], strlen($row['method'])),
            $this->methodColour($row['method']),
            true
        ) . substr($left, strlen($row['method']));

        $colouredRight = $row['name'] !== ''
            ? $this->output->color($row['name'], 'cyan') . ' › ' . $this->output->color($row['action'], 'yellow')
            : $this->output->color($row['action'], 'yellow');

        return '  ' . $colouredLeft . ' '
            . $this->output->color(str_repeat('.', $dots), 'gray')
            . ' ' . $colouredRight;
    }

    private function widest(array $rows, string $key, int $minimum): int
    {
        return max($minimum, ...array_map(static fn (array $row): int => strlen($row[$key]), $rows));
    }

    /** Read verbs green, writes yellow, deletes red — scannable at a glance. */
    private function methodColour(string $method): string
    {
        return match (strtoupper($method)) {
            'GET', 'HEAD' => 'green',
            'POST', 'PUT', 'PATCH' => 'yellow',
            'DELETE' => 'red',
            default => 'blue',
        };
    }
}
