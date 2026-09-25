<?php

namespace Nitro\Tests\Compat;

use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Http\Kernel;
use Nitro\Foundation\Application;
use Nitro\Tests\TestCase;

class ApplicationContractTest extends TestCase
{
    public function test_implements_the_contracts_packages_check(): void
    {
        $app = $this->boot();

        $this->assertInstanceOf(ApplicationContract::class, $app);
        $this->assertInstanceOf(CachesConfiguration::class, $app);
        $this->assertInstanceOf(CachesRoutes::class, $app);
        $this->assertSame($app, app());
        $this->assertSame($app, $app->make(ApplicationContract::class));
        $this->assertSame($app, $app->make(\Illuminate\Contracts\Container\Container::class));
        $this->assertSame($app, $app->make(\Illuminate\Container\Container::class));
        $this->assertInstanceOf(\Nitro\Foundation\HttpKernel::class, $app->make(Kernel::class));
    }

    public function test_version_is_laravel_shaped(): void
    {
        $app = $this->boot();

        $this->assertMatchesRegularExpression('/^13\.\d+\.\d+$/', $app->version());
        $this->assertTrue(version_compare($app->version(), '11.0', '>='));
    }

    public function test_paths(): void
    {
        $app = $this->boot();
        $base = realpath(self::APP);

        $this->assertSame($base, realpath($app->basePath()));
        $this->assertSame($app->basePath('config'.DIRECTORY_SEPARATOR.'app.php'), $app->configPath('app.php'));
        $this->assertSame($app->basePath('storage'), $app->storagePath());
        $this->assertSame($app->basePath('storage'), $app['path.storage']);
        $this->assertSame($app->basePath('resources'.DIRECTORY_SEPARATOR.'views'), resource_path('views'));
        $this->assertSame($app->basePath('app'), app_path());
        $this->assertSame($app->basePath('public'), public_path());
        $this->assertSame($app->basePath('database'), database_path());

        $app->useStoragePath($custom = sys_get_temp_dir());
        $this->assertSame($custom, $app->storagePath());
        $this->assertSame($custom, $app['path.storage']);
    }

    public function test_environment(): void
    {
        $app = $this->boot();

        $this->assertSame('testing', $app->environment());
        $this->assertTrue($app->environment('testing'));
        $this->assertTrue($app->environment(['local', 'test*']));
        $this->assertFalse($app->environment('production'));
        $this->assertTrue($app->runningUnitTests());
        $this->assertTrue($app->hasDebugModeEnabled());
        $this->assertFalse($app->isDownForMaintenance());
        $this->assertSame('en', $app->getLocale());
    }

    public function test_locale_updates_translator(): void
    {
        $app = $this->boot();

        $app->make('translator');
        $app->setLocale('fr');

        $this->assertSame('fr', $app->getLocale());
        $this->assertSame('fr', $app->make('translator')->getLocale());
    }

    public function test_lifecycle_callbacks(): void
    {
        $app = new Application(self::APP);
        $calls = [];

        $app->booting(function () use (&$calls) { $calls[] = 'booting'; });
        $app->booted(function () use (&$calls) { $calls[] = 'booted'; });
        $app->terminating(function () use (&$calls) { $calls[] = 'terminating'; });

        $app->boot();
        $app->booted(function () use (&$calls) { $calls[] = 'booted-late'; });
        $app->terminate();

        $this->assertSame(['booting', 'booted', 'booted-late', 'terminating'], $calls);
    }

    public function test_caches_are_reported(): void
    {
        $app = $this->boot(cached: true);

        $this->assertTrue($app->configurationIsCached());
        $this->assertTrue($app->routesAreCached());
        $this->assertFileExists($app->getCachedServicesPath());
        $this->assertFileExists($app->getCachedPackagesPath());
    }

    public function test_core_services_are_lazy(): void
    {
        $app = $this->boot();

        foreach (['view', 'validator', 'translator', 'cache', 'session', 'auth', 'hash', 'encrypter', 'queue', 'filesystem', 'log'] as $service) {
            $this->assertFalse($app->resolved($service), "[{$service}] was built during bootstrap");
        }
    }
}
