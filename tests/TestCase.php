<?php

namespace Nitro\Tests;

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Facade;
use Nitro\Foundation\Application;
use Nitro\Tests\Fixtures\Package\AcmeDeferredProvider;
use Nitro\Tests\Fixtures\Package\AcmeServiceProvider;
use Nitro\Tests\Fixtures\Package\AcmeWhenProvider;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Symfony\Component\HttpFoundation\Response;

abstract class TestCase extends BaseTestCase
{
    public const APP = __DIR__.DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'app';

    protected ?Application $app = null;

    protected function setUp(): void
    {
        parent::setUp();

        static::clearCaches();
        static::resetPackageCounters();
    }

    protected function tearDown(): void
    {
        $this->app = null;

        $this->flushGlobalState();
        static::clearCaches();

        parent::tearDown();
    }

    public static function clearCaches(): void
    {
        // packages.php / services.php are identical for every test; rebuilding them each time only
        // races Windows file locks (Laravel's Filesystem::replace renames over the old file).
        foreach (glob(self::APP.'/bootstrap/cache/*') ?: [] as $file) {
            if (! in_array(basename($file), ['packages.php', 'services.php', '.gitignore'], true)) {
                @unlink($file);
            }
        }

        foreach (glob(self::APP.'/storage/framework/views/*.php') ?: [] as $file) {
            @unlink($file);
        }

        @unlink(self::APP.'/preload.php');
    }

    protected static function resetPackageCounters(): void
    {
        AcmeServiceProvider::$registered = AcmeServiceProvider::$booted = 0;
        AcmeDeferredProvider::$registered = 0;
        AcmeWhenProvider::$registered = 0;
    }

    protected function flushGlobalState(): void
    {
        HandleExceptions::flushState($this);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
    }

    /**
     * A booted fixture app, as for an HTTP request. $cached = true runs config:cache and
     * route:cache first (like `php artisan optimize`), then boots a new app from those caches.
     */
    protected function boot(bool $cached = false): Application
    {
        if ($cached) {
            $this->optimize();
        }

        $app = $this->app = require self::APP.'/bootstrap/app.php';
        static::runningInConsole($app, false);
        $app->make(HttpKernel::class)->bootstrap();

        return $app;
    }

    protected function bootConsole(): Application
    {
        $app = $this->app = require self::APP.'/bootstrap/app.php';
        static::runningInConsole($app, true);
        $app->make(ConsoleKernel::class)->bootstrap();

        return $app;
    }

    /**
     * A booted stock Laravel application (Illuminate\Foundation\Application, Laravel's own
     * kernels and providers) over the same fixture app: the reference for parity tests.
     */
    protected function bootLaravel(): LaravelApplication
    {
        $app = LaravelApplication::configure(basePath: self::APP)
            ->withRouting(web: self::APP.'/routes/web.php', api: self::APP.'/routes/api.php')
            ->withMiddleware(static function (Middleware $middleware): void {})
            ->withExceptions(static function (Exceptions $exceptions): void {})
            ->create();

        /** Its own bootstrap cache: Laravel's provider list differs, so its services.php does too. */
        $app->useBootstrapPath(self::laravelBootstrapPath());
        $app->make(ConsoleKernel::class)->bootstrap();

        return $app;
    }

    /**
     * Run one scenario against stock Laravel, then against Nitro. The scenario receives a
     * booter (call it for each application it needs) and returns what it observed.
     *
     * @return array{laravel: mixed, nitro: mixed}
     */
    protected function parity(callable $scenario): array
    {
        static::flushFrameworkStatics();
        $laravel = $scenario(fn () => $this->bootLaravel());
        $this->flushGlobalState();

        /** What stock Laravel registered process-wide must not answer for Nitro. */
        static::flushFrameworkStatics();
        $nitro = $scenario(fn () => $this->bootConsole());
        $this->flushGlobalState();

        return ['laravel' => $laravel, 'nitro' => $nitro];
    }

    /**
     * Process-wide hooks that providers register statically (e.g. ContextServiceProvider's
     * queue payload callback).
     */
    protected static function flushFrameworkStatics(): void
    {
        Queue::createPayloadUsing(null);
    }

    protected static function laravelBootstrapPath(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nitro-tests-laravel-bootstrap';

        if (! is_dir($path.DIRECTORY_SEPARATOR.'cache')) {
            mkdir($path.DIRECTORY_SEPARATOR.'cache', 0777, true);
        }

        return $path;
    }

    /**
     * Set console detection directly; the application has no public setter for it.
     */
    protected static function runningInConsole(LaravelApplication $app, bool $console): void
    {
        (fn () => $this->isRunningInConsole = $console)->call($app);
    }

    protected function optimize(): void
    {
        $app = $this->bootConsole();
        $kernel = $app->make(ConsoleKernel::class);

        foreach (['config:cache', 'route:cache'] as $command) {
            if ($kernel->call($command) !== 0) {
                $this->fail("{$command} failed: ".$kernel->output());
            }
        }

        $this->app = null;
        $this->flushGlobalState();

        // The optimizer's fresh boots are not the boot under test.
        static::resetPackageCounters();
    }

    protected function call(string $method, string $uri, array $parameters = [], array $server = [], array $cookies = []): Response
    {
        $app = $this->app ?? $this->boot();
        $kernel = $app->make(HttpKernel::class);

        $request = Request::create($uri, $method, $parameters, $cookies, [], $server);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    protected function get(string $uri, array $server = []): Response
    {
        return $this->call('GET', $uri, [], $server);
    }

    /**
     * Run the same assertions against uncached and fully cached boots.
     */
    public static function modes(): array
    {
        return ['uncached' => [false], 'cached' => [true]];
    }
}
