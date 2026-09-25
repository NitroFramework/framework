<?php

namespace Nitro\Tests\Compat;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Nitro\Components\Registry;
use Nitro\Console\Kernel as ConsoleKernel;
use Nitro\Tests\Fixtures\Package\Acme;
use Nitro\Tests\Fixtures\Package\AcmeDeferredProvider;
use Nitro\Tests\Fixtures\Package\AcmeEvent;
use Nitro\Tests\Fixtures\Package\AcmeGreeter;
use Nitro\Tests\Fixtures\Package\AcmeServiceProvider;
use Nitro\Tests\Fixtures\Package\AcmeWhenEvent;
use Nitro\Tests\Fixtures\Package\AcmeWhenProvider;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The fixture package (tests/Fixtures/Package) is written like any Laravel package and is
 * discovered through vendor/composer/installed.json, exactly as composer would install it.
 */
class PackageCompatibilityTest extends TestCase
{
    public function test_packages_are_discovered_and_dont_discover_is_honored(): void
    {
        $app = $this->boot();
        $manifest = $app->make(PackageManifest::class);

        $this->assertSame(['acme/nitro-package'], array_keys(require $app->getCachedPackagesPath()));
        $this->assertContains(AcmeServiceProvider::class, $manifest->providers());
        $this->assertSame(['Acme' => Acme::class], $manifest->aliases());
    }

    #[DataProvider('modes')]
    public function test_eager_provider_registers_and_boots_once(bool $cached): void
    {
        $app = $this->boot($cached);

        $this->assertSame(1, AcmeServiceProvider::$registered);
        $this->assertSame(1, AcmeServiceProvider::$booted);
        $this->assertTrue($app->providerIsLoaded(AcmeServiceProvider::class));
        $this->assertInstanceOf(AcmeServiceProvider::class, $app->getProvider(AcmeServiceProvider::class));
    }

    #[DataProvider('modes')]
    public function test_merge_config_from_works_cached_and_uncached(bool $cached): void
    {
        $app = $this->boot($cached);

        $this->assertSame('Hello', $app['config']['acme.greeting']);
        $this->assertTrue(config('acme.enabled'));
        $this->assertSame($cached, $app->configurationIsCached());
    }

    #[DataProvider('modes')]
    public function test_deferred_provider_loads_only_when_its_service_is_resolved(bool $cached): void
    {
        $app = $this->boot($cached);

        $this->assertSame(0, AcmeDeferredProvider::$registered);
        $this->assertTrue($app->bound('acme.deferred'));
        $this->assertTrue($app->isDeferredService('acme.deferred'));

        $this->assertTrue($app->make('acme.deferred')['deferred']);
        $this->assertSame(1, AcmeDeferredProvider::$registered);

        $app->make('acme.deferred');
        $this->assertSame(1, AcmeDeferredProvider::$registered);
    }

    #[DataProvider('modes')]
    public function test_event_triggered_provider(bool $cached): void
    {
        $this->boot($cached);

        $this->assertSame(0, AcmeWhenProvider::$registered);

        event(new AcmeWhenEvent);

        $this->assertSame(1, AcmeWhenProvider::$registered);
    }

    #[DataProvider('modes')]
    public function test_package_routes_views_middleware_and_facades(bool $cached): void
    {
        $this->boot($cached);

        $response = $this->get('/acme');

        $this->assertSame('Hello, package', $response->getContent());
        $this->assertSame('route', $response->headers->get('X-Acme'), 'aliasMiddleware() from boot()');
        $this->assertSame('yes', $response->headers->get('X-Acme-Web'), 'pushMiddlewareToGroup() from boot()');
        $this->assertSame('yes', $response->headers->get('X-Acme-Global'), 'Kernel::pushMiddleware() from boot()');

        $this->assertSame("Acme view: Nitro\n", $this->get('/acme/view')->getContent());
        $this->assertSame('http://localhost/acme', route('acme.home'));

        // App routes get the package's web-group middleware too.
        $this->assertSame('yes', $this->get('/')->headers->get('X-Acme-Web'));
    }

    #[DataProvider('modes')]
    public function test_facades_aliases_and_contract_injection(bool $cached): void
    {
        $app = $this->boot($cached);

        $this->assertSame('Hello, facade', Acme::greet('facade'));
        $this->assertSame('Hello, alias', \Acme::greet('alias'));
        $this->assertSame('str', \Str::lower('STR'));
        $this->assertSame($app->make('acme.greeter'), $app->make(AcmeGreeter::class));

        $this->assertTrue(Event::dispatch(new AcmeEvent) !== null);
        $event = new AcmeEvent;
        event($event);
        $this->assertTrue($event->handled);
    }

    public function test_package_commands_and_vendor_publish(): void
    {
        // The package registers commands only when running in console, at boot.
        $app = $this->bootConsole();
        $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);

        $this->assertSame(0, $kernel->call('acme:hello', ['name' => 'console']));
        $this->assertSame('Hello, console', trim($kernel->output()));

        $this->assertSame([$app->configPath('acme.php')], array_values(ServiceProvider::pathsToPublish(AcmeServiceProvider::class)));
        $this->assertContains('acme-config', ServiceProvider::publishableGroups());

        $target = $app->configPath('acme.php');
        @unlink($target);

        try {
            $this->assertSame(0, $kernel->call('vendor:publish', ['--tag' => ['acme-config']]));
            $this->assertFileExists($target);
        } finally {
            @unlink($target);
        }
    }

    public function test_component_providers_are_skipped_but_report_as_loaded(): void
    {
        $app = $this->boot();

        foreach (Registry::PROVIDERS as $provider) {
            $this->assertTrue($app->providerIsLoaded($provider), "{$provider} should report as loaded");
            $this->assertArrayHasKey($provider, $app->getLoadedProviders());
            $this->assertNull($app->getProvider($provider), "{$provider} should never be instantiated");
        }

        /** Registering one explicitly (as a package might) stays a no-op. */
        $app->register(CacheServiceProvider::class);
        $this->assertNull($app->getProvider(CacheServiceProvider::class));

        /** The same holds after flush(), which resets the container's provider state. */
        $app->flush();
        $this->assertTrue($app->providerIsLoaded(CacheServiceProvider::class));

        $app = $this->boot();
        $this->assertInstanceOf(CacheManager::class, $app->make('cache'));
        $this->assertTrue($app->providerIsLoaded(\Illuminate\Foundation\Providers\FoundationServiceProvider::class));
        $this->assertTrue($app->providerIsLoaded(\Illuminate\Filesystem\FilesystemServiceProvider::class));
    }

    public function test_provider_manifest_is_cached_and_classifies_providers(): void
    {
        $app = $this->boot();
        $manifest = require $app->getCachedServicesPath();

        $this->assertContains(AcmeServiceProvider::class, $manifest['eager']);
        $this->assertSame(AcmeDeferredProvider::class, $manifest['deferred']['acme.deferred']);
        $this->assertSame([AcmeWhenEvent::class], $manifest['when'][AcmeWhenProvider::class]);
    }
}
