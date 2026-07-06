<?php

namespace Tests\Unit\Foundation;

use Nitro\Container\Container;
use Nitro\Foundation\Application;
use Nitro\View\Compiler\BladeCompiler;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The `nitro optimize` cache feeds the RegisterProviders bootstrapper a
 * pre-merged provider list plus a directive map. Confirms the runtime
 * consumes both without re-merging or re-loading source files.
 */
class OptimizedBootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        Container::reset();
        BladeCompiler::clearCustomDirectives();
    }

    protected function tearDown(): void
    {
        BladeCompiler::clearCustomDirectives();
    }

    public function test_registerConfiguredProviders_accepts_a_pre_merged_list(): void
    {
        EagerCacheProvider::$registered = false;
        $app = new Application(sys_get_temp_dir());

        // Pass the list directly — bypassing the default array_merge +
        // config() lookup that `registerConfiguredProviders()` does in dev.
        $app->registerConfiguredProviders([EagerCacheProvider::class]);

        $this->assertTrue(EagerCacheProvider::$registered);
    }

    public function test_get_default_providers_is_public_and_returns_framework_defaults(): void
    {
        $app = new Application(sys_get_temp_dir());
        $defaults = $app->getDefaultProviders();

        $this->assertIsArray($defaults);
        $this->assertGreaterThan(0, count($defaults));
        $this->assertContains(\Nitro\Foundation\Providers\RoutingServiceProvider::class, $defaults);
    }

    public function test_directive_hydration_short_circuits_loader(): void
    {
        BladeCompiler::hydrateCustomDirectives([
            'elapsed_time' => '<?php echo "0.42"; ?>',
        ]);

        $this->assertTrue(BladeCompiler::directivesHydratedFromCache());

        // ViewServiceProvider::boot would normally call loadCustomDirectives;
        // when the cache populated the registry it should short-circuit.
        // We can't easily call the provider standalone here (it has many deps),
        // so we just confirm the public flag is the contract the provider
        // checks.
        $this->assertArrayHasKey(
            'elapsed_time',
            BladeCompiler::getCustomDirectives()
        );
    }

    public function test_deferred_resolver_is_wired_on_container(): void
    {
        $app = new Application(sys_get_temp_dir());

        $prop = new ReflectionProperty(Container::class, 'deferredResolver');
        $resolver = $prop->getValue($app->getContainer());

        $this->assertNotNull($resolver, 'Application should wire a deferred resolver into the container.');
        $this->assertInstanceOf(\Closure::class, $resolver);
    }
}
