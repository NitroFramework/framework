<?php

namespace Tests\Unit\Foundation;

use Nitro\Container\Container;
use Nitro\Foundation\Application;
use Nitro\Foundation\Providers\ServiceProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Confirms providers with a boot() method are recorded at register() time so
 * bootProviders() doesn't have to call method_exists() per provider per
 * request.
 */
class BootableProviderCacheTest extends TestCase
{
    protected function setUp(): void
    {
        Container::reset();
    }

    protected function makeApp(): Application
    {
        // Application takes a base path string and wires its own PathRegistry.
        return new Application(sys_get_temp_dir());
    }

    protected function bootable(Application $app): array
    {
        $p = new ReflectionProperty(Application::class, 'bootableProviders');
        return $p->getValue($app);
    }

    public function test_only_providers_with_boot_method_are_cached(): void
    {
        $app = $this->makeApp();
        $app->register(BootableTestProvider::class);
        $app->register(NonBootableTestProvider::class);

        $cached = $this->bootable($app);
        $this->assertCount(1, $cached);
        $this->assertInstanceOf(BootableTestProvider::class, $cached[0]);
    }

    public function test_boot_providers_calls_boot_in_registration_order(): void
    {
        BootOrderTracker::$calls = [];

        $app = $this->makeApp();
        $app->register(BootProviderA::class);
        $app->register(BootProviderB::class);

        $app->bootProviders();

        $this->assertSame(['A', 'B'], BootOrderTracker::$calls);
    }

    public function test_registering_same_provider_twice_does_not_duplicate(): void
    {
        $app = $this->makeApp();
        $app->register(BootableTestProvider::class);
        $app->register(BootableTestProvider::class);

        $this->assertCount(1, $this->bootable($app));
    }
}

class BootOrderTracker
{
    public static array $calls = [];
}

class BootableTestProvider extends ServiceProvider
{
    public function register(): void {}
    public function boot(): void {}
}

class NonBootableTestProvider extends ServiceProvider
{
    public function register(): void {}
}

class BootProviderA extends ServiceProvider
{
    public function register(): void {}
    public function boot(): void { BootOrderTracker::$calls[] = 'A'; }
}

class BootProviderB extends ServiceProvider
{
    public function register(): void {}
    public function boot(): void { BootOrderTracker::$calls[] = 'B'; }
}
