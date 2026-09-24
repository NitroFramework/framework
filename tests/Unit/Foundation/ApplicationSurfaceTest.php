<?php

namespace Tests\Unit\Foundation;

use Nitro\Container\Container;
use Nitro\Foundation\Application;
use Nitro\Foundation\Bootstrap\LoadConfiguration;
use Nitro\Foundation\Bootstrap\LoadEnvironment;
use Nitro\Foundation\Bootstrap\RegisterProviders;
use Nitro\Foundation\MaintenanceMode;
use Nitro\Foundation\Providers\ServiceProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the application can be asked, beyond running.
 *
 * Each of these answers a question something outside the framework has: a
 * package needing to act between two boot stages, a command asking whether a
 * cache exists before offering to clear it, middleware asking which locale is
 * in use. Without them the caller reaches inside — for a path it guesses, a
 * provider list it walks, a translator it should not have to name.
 */
class ApplicationSurfaceTest extends TestCase
{
    /** How many handlers were installed, so tearDown can put them back. */
    private int $bootstraps = 0;

    private function app(): Application
    {
        Container::reset();

        $this->bootstraps++;

        return new Application(dirname(__DIR__, 3));
    }

    /**
     * bootstrap() installs error and exception handlers, through the same
     * bootstrapper a real request goes through. Left in place they outlive the
     * test, which PHPUnit rightly calls risky.
     */
    protected function tearDown(): void
    {
        while ($this->bootstraps-- > 0) {
            restore_error_handler();
            restore_exception_handler();
        }

        $this->bootstraps = 0;

        Container::reset();

        parent::tearDown();
    }

    // ── Bootstrap hooks ─────────────────────────────────────

    /**
     * The seam a package needs: after configuration is read, before providers
     * register. A provider's own boot() is too late — registration is done.
     */
    public function test_a_hook_runs_before_and_after_a_named_bootstrapper(): void
    {
        $app = $this->app();
        $order = [];

        $app->beforeBootstrapping(RegisterProviders::class, function () use (&$order): void {
            $order[] = 'before';
        });

        $app->afterBootstrapping(RegisterProviders::class, function () use (&$order): void {
            $order[] = 'after';
        });

        $app->bootstrap();

        $this->assertSame(['before', 'after'], $order);
    }

    public function test_a_hook_is_given_the_application_and_the_stage(): void
    {
        $app = $this->app();
        $seen = [];

        $app->afterBootstrapping(LoadConfiguration::class, function ($given, $stage) use (&$seen): void {
            $seen = [$given, $stage];
        });

        $app->bootstrap();

        $this->assertSame($app, $seen[0]);
        $this->assertSame(LoadConfiguration::class, $seen[1]);
    }

    /** Naming a stage that is not in the sequence does nothing rather than throw. */
    public function test_a_hook_on_an_absent_stage_never_fires(): void
    {
        $app = $this->app();
        $fired = false;

        $app->beforeBootstrapping('App\\Bootstrap\\DoesNotExist', function () use (&$fired): void {
            $fired = true;
        });

        $app->bootstrap();

        $this->assertFalse($fired);
    }

    public function test_afterLoadingEnvironment_runs_once_env_is_readable(): void
    {
        $app = $this->app();
        $stage = null;

        $app->afterLoadingEnvironment(function ($app, $given) use (&$stage): void {
            $stage = $given;
        });

        $app->bootstrap();

        $this->assertSame(LoadEnvironment::class, $stage);
    }

    public function test_it_reports_whether_it_has_been_bootstrapped(): void
    {
        $app = $this->app();

        $this->assertFalse($app->hasBeenBootstrapped());

        $app->bootstrap();

        $this->assertTrue($app->hasBeenBootstrapped());
        $this->assertSame($app->isBootstrapped(), $app->hasBeenBootstrapped());
    }

    // ── Provider introspection ──────────────────────────────

    public function test_a_provider_can_be_asked_about_by_class(): void
    {
        $app = $this->app();
        $app->bootstrap();

        $registered = array_key_first($app->getLoadedProviders());

        $this->assertNotNull($registered);
        $this->assertTrue($app->providerIsLoaded($registered));
        $this->assertInstanceOf(ServiceProvider::class, $app->getProvider($registered));
    }

    public function test_a_provider_that_never_registered_is_reported_absent(): void
    {
        $app = $this->app();
        $app->bootstrap();

        $this->assertFalse($app->providerIsLoaded('App\\Providers\\NeverRegistered'));
        $this->assertNull($app->getProvider('App\\Providers\\NeverRegistered'));
    }

    /** Building one without registering it, for a caller that only wants to ask. */
    public function test_a_provider_can_be_built_without_being_registered(): void
    {
        $app = $this->app();
        $app->bootstrap();

        $provider = $app->resolveProvider(\Nitro\Queue\QueueServiceProvider::class);

        $this->assertInstanceOf(ServiceProvider::class, $provider);
        $this->assertFalse($app->providerIsLoaded(\Nitro\Queue\QueueServiceProvider::class));
    }

    public function test_a_deferred_service_is_reported_as_deferred(): void
    {
        $app = $this->app();
        $app->bootstrap();

        $deferred = array_key_first($app->getDeferredServices());

        $this->assertNotNull($deferred, 'the application should defer something');
        $this->assertTrue($app->isDeferredService($deferred));
        $this->assertFalse($app->isDeferredService('nothing.defers.this'));
    }

    // ── Is it cached? ───────────────────────────────────────

    public function test_the_cache_questions_answer_from_the_registry(): void
    {
        $app = $this->app();
        $app->bootstrap();

        $paths = $app->paths();

        $this->assertSame(is_file($paths->cachedConfig()), $app->configurationIsCached());
        $this->assertSame(is_file($paths->cachedRoutes()), $app->routesAreCached());
        $this->assertSame(is_file($paths->cachedEvents()), $app->eventsAreCached());
        $this->assertSame(is_file($paths->cachedProviders()), $app->providersAreCached());
    }

    // ── Locale ──────────────────────────────────────────────

    public function test_the_locale_can_be_read_and_set_through_the_application(): void
    {
        $app = $this->app();
        $app->bootstrap();

        $this->assertNotSame('', $app->getLocale());
        $this->assertSame($app->getLocale(), $app->currentLocale());

        $app->setLocale('fr');

        $this->assertSame('fr', $app->getLocale());
        $this->assertTrue($app->isLocale('fr'));
        $this->assertFalse($app->isLocale('de'));
    }

    public function test_the_fallback_locale_can_be_read_and_set(): void
    {
        $app = $this->app();
        $app->bootstrap();

        $this->assertNotSame('', $app->getFallbackLocale());

        $app->setFallbackLocale('de');

        $this->assertSame('de', $app->getFallbackLocale());
    }

    // ── Maintenance mode ────────────────────────────────────

    /**
     * Reached, not only asked: a file on one machine's disk is invisible to
     * every other machine behind a load balancer, so the driver has to be
     * swappable.
     */
    public function test_maintenance_mode_is_reachable_not_just_answerable(): void
    {
        $app = $this->app();
        $app->bootstrap();

        $this->assertInstanceOf(MaintenanceMode::class, $app->maintenanceMode());
        $this->assertSame($app->maintenanceMode()->active(), $app->isDownForMaintenance());
    }
}
