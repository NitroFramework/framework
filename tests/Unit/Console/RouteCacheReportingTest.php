<?php

namespace Tests\Unit\Console;

use Nitro\Console\Commands\RouteCommands;
use Nitro\Console\ExitCode;
use Nitro\Console\OutputFormatter;
use Nitro\Container\Container;
use Nitro\Container\ContainerClassResolver;
use Nitro\Foundation\Config;
use Nitro\Foundation\PathRegistry;
use Nitro\Routing\RouteLoader;
use Nitro\Routing\RouteTypes;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;

/**
 * `route:cache` has to report what it did, not what it attempted.
 *
 * {@see RouteLoader::cache()} returns the number of routes that stopped it
 * writing a cache file, and the command discarded that value. An application
 * with one such route was told "Routes cached successfully!", shown a cache
 * file that did not exist, and handed exit code 0 — so a deploy script running
 * `nitro route:cache && ...` carried straight on, and the app served every
 * request off the uncached path with nobody aware of it.
 *
 * A plain closure is not what blocks it: those are serialized. A handler
 * holding something that cannot be written out at all — an open connection, a
 * part-consumed generator — is.
 */
class RouteCacheReportingTest extends TestCase
{
    private string $appRoot;

    protected function setUp(): void
    {
        $this->appRoot = sys_get_temp_dir() . '/nitro-routecmd-' . bin2hex(random_bytes(4));
        mkdir($this->appRoot . '/routes', 0777, true);
        mkdir($this->appRoot . '/cache/routes', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/cache/routes/routes.php', '/routes/web.php'] as $file) {
            @unlink($this->appRoot . $file);
        }

        foreach (['/cache/routes', '/cache', '/routes', ''] as $dir) {
            @rmdir($this->appRoot . $dir);
        }
    }

    /**
     * The bug, stated as a test: a closure route means no cache file, so the
     * command must fail and say so.
     */
    public function test_a_refused_cache_is_reported_as_a_failure(): void
    {
        $this->writeRoutes(<<<'PHP'
        $stream = (function () { yield 'row'; })();
        $router->get('/users', 'UserController@index');
        $router->get('/ping', function () use ($stream) { return $stream; });
        PHP);

        [$exitCode, $output] = $this->invoke('route:cache');

        $this->assertSame(ExitCode::FAILURE, $exitCode, 'a refused cache must not exit 0');
        $this->assertFileDoesNotExist($this->cacheFile());
        $this->assertStringNotContainsString('successfully', $output);
        $this->assertStringContainsString('NOT cached', $output);
    }

    /** And it must name the route to fix, not just count it. */
    public function test_a_refused_cache_names_the_blocking_route(): void
    {
        $this->writeRoutes(<<<'PHP'
        $stream = (function () { yield 'row'; })();
        $router->get('/users', 'UserController@index');
        $router->get('/ping', function () use ($stream) { return $stream; });
        PHP);

        [, $output] = $this->invoke('route:cache');

        $this->assertStringContainsString('/ping', $output);
        $this->assertStringNotContainsString('/users', $output, 'only the blocking routes should be listed');
    }

    /** The happy path still succeeds, and writes the file it claims to. */
    public function test_a_written_cache_is_reported_as_a_success(): void
    {
        $this->writeRoutes(<<<'PHP'
        $router->get('/users', 'UserController@index');
        $router->get('/posts', 'PostController@index');
        PHP);

        [$exitCode, $output] = $this->invoke('route:cache');

        $this->assertSame(ExitCode::SUCCESS, $exitCode);
        $this->assertFileExists($this->cacheFile());
        $this->assertStringContainsString('Routes cached successfully!', $output);
        $this->assertStringContainsString($this->cacheFile(), $output, 'the path shown must be the file written');
    }

    /**
     * The count must be the routes, not the nodes of the array holding them.
     *
     * It was count($routes, COUNT_RECURSIVE), which walks into every handler
     * array — an app with 34 routes was told it had cached 643.
     */
    public function test_the_route_count_is_the_number_of_routes(): void
    {
        $this->writeRoutes(<<<'PHP'
        $router->get('/users', 'UserController@index');
        $router->get('/posts', 'PostController@index');
        $router->post('/posts', 'PostController@store');
        PHP);

        [, $output] = $this->invoke('route:cache');

        $this->assertStringContainsString('Cached 3 routes.', $output);
    }

    /** Nothing to clear is not a clearing, and must not print the banner. */
    public function test_clearing_an_absent_cache_does_not_claim_to_have_cleared_one(): void
    {
        $this->writeRoutes("\$router->get('/users', 'UserController@index');");

        [$exitCode, $output] = $this->invoke('route:clear');

        $this->assertSame(ExitCode::SUCCESS, $exitCode);
        $this->assertStringContainsString('No route cache found', $output);
        $this->assertStringNotContainsString('Route cache cleared!', $output);
    }

    private function writeRoutes(string $body): void
    {
        file_put_contents($this->appRoot . '/routes/web.php', "<?php\n\n" . $body . "\n");
    }

    private function cacheFile(): string
    {
        return $this->appRoot . '/cache/routes' . DIRECTORY_SEPARATOR . 'routes.php';
    }

    /**
     * Run a command against a throwaway application and capture its output.
     *
     * @return array{0: int, 1: string}
     */
    private function invoke(string $command): array
    {
        $paths = new class($this->appRoot) extends PathRegistry {
            public function __construct(private string $root) {}
            public function base(string $path = ''): string { return $this->root . '/' . $path; }
            public function cache(string $path = ''): string { return $this->root . '/cache/' . $path; }
            public function config(string $path = ''): string { return $this->root . '/config/' . $path; }
            public function cachedRoutes(): string { return $this->cache('routes' . DIRECTORY_SEPARATOR . 'routes.php'); }
        };

        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn('App\\Controllers\\');

        $commands = new RouteCommands(
            new RouteLoader($paths, $config, new ContainerClassResolver(new Container())),
            new Router($config, new RouteTypes()),
            $paths,
            new OutputFormatter(),
        );

        ob_start();
        $exitCode = $commands->handle($command, []);
        $output = (string) ob_get_clean();

        return [$exitCode, $output];
    }
}
