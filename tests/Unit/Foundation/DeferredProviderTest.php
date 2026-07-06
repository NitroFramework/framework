<?php

namespace Tests\Unit\Foundation;

use Nitro\Container\Container;
use Nitro\Foundation\Application;
use Nitro\Foundation\Providers\ServiceProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Deferred providers must not run register() / boot() until one of their
 * provides() services is asked for. This is a sizeable bootstrap win for
 * apps where most requests don't touch every provider.
 */
class DeferredProviderTest extends TestCase
{
    protected function setUp(): void
    {
        Container::reset();
        DeferredProviderTracker::reset();
    }

    protected function app(): Application
    {
        return new Application(sys_get_temp_dir());
    }

    public function test_deferred_provider_does_not_register_eagerly(): void
    {
        $app = $this->app();
        $app->register(DeferredCacheProvider::class);

        $this->assertFalse(DeferredProviderTracker::$registered);
        $this->assertFalse(DeferredProviderTracker::$booted);
        // The service is not yet bound; container probe must fail until
        // somebody resolves it.
        $this->assertFalse($app->getContainer()->has('cache.deferred'));
    }

    public function test_resolving_provided_service_triggers_registration_and_boot(): void
    {
        $app = $this->app();
        $app->register(DeferredCacheProvider::class);

        // Container::make probes the deferred resolver; that loads the
        // provider, runs register() then boot(), and returns the bound service.
        $result = $app->getContainer()->make('cache.deferred');

        $this->assertTrue(DeferredProviderTracker::$registered);
        $this->assertTrue(DeferredProviderTracker::$booted);
        $this->assertSame('deferred-cache-instance', $result);
    }

    public function test_deferred_services_map_is_populated_on_register(): void
    {
        $app = $this->app();
        $app->register(DeferredCacheProvider::class);

        $prop = new ReflectionProperty(Application::class, 'deferredServices');
        $map = $prop->getValue($app);

        $this->assertArrayHasKey('cache.deferred', $map);
        $this->assertArrayHasKey('cache.deferred.alias', $map);
        $this->assertSame(DeferredCacheProvider::class, $map['cache.deferred']);
    }

    public function test_resolving_one_service_removes_all_provider_entries_from_map(): void
    {
        $app = $this->app();
        $app->register(DeferredCacheProvider::class);

        $app->getContainer()->make('cache.deferred');

        $prop = new ReflectionProperty(Application::class, 'deferredServices');
        $map = $prop->getValue($app);

        // Both 'cache.deferred' and its alias should be gone now that the
        // provider has registered.
        $this->assertArrayNotHasKey('cache.deferred', $map);
        $this->assertArrayNotHasKey('cache.deferred.alias', $map);
    }

    public function test_provider_registers_only_once_even_when_multiple_services_requested(): void
    {
        $app = $this->app();
        $app->register(DeferredCacheProvider::class);

        $app->getContainer()->make('cache.deferred');
        $app->getContainer()->make('cache.deferred.alias');

        $this->assertSame(1, DeferredProviderTracker::$registerCalls);
        $this->assertSame(1, DeferredProviderTracker::$bootCalls);
    }

    public function test_non_deferred_provider_runs_register_eagerly(): void
    {
        $app = $this->app();
        $app->register(EagerCacheProvider::class);

        $this->assertTrue(EagerCacheProvider::$registered);
        $this->assertTrue($app->getContainer()->has('cache.eager'));
    }

    public function test_unknown_abstract_falls_through_to_normal_error(): void
    {
        $app = $this->app();
        $app->register(DeferredCacheProvider::class);

        $this->expectException(\RuntimeException::class);
        $app->getContainer()->get('definitely.not.registered');
    }
}

class DeferredProviderTracker
{
    public static bool $registered = false;
    public static bool $booted = false;
    public static int $registerCalls = 0;
    public static int $bootCalls = 0;

    public static function reset(): void
    {
        self::$registered = false;
        self::$booted = false;
        self::$registerCalls = 0;
        self::$bootCalls = 0;
    }
}

class DeferredCacheProvider extends ServiceProvider
{
    protected bool $defer = true;

    public function provides(): array
    {
        return ['cache.deferred', 'cache.deferred.alias'];
    }

    public function register(): void
    {
        DeferredProviderTracker::$registered = true;
        DeferredProviderTracker::$registerCalls++;
        $this->container->instance('cache.deferred', 'deferred-cache-instance');
        $this->container->instance('cache.deferred.alias', 'deferred-cache-instance');
    }

    public function boot(): void
    {
        DeferredProviderTracker::$booted = true;
        DeferredProviderTracker::$bootCalls++;
    }
}

class EagerCacheProvider extends ServiceProvider
{
    public static bool $registered = false;

    public function register(): void
    {
        self::$registered = true;
        $this->container->instance('cache.eager', 'eager-instance');
    }
}
