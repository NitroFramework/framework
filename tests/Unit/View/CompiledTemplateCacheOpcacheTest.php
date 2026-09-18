<?php

namespace Tests\Unit\View;

use Nitro\Foundation\Config;
use Nitro\Foundation\PathRegistry;
use Nitro\View\Compiler\CompiledTemplateCache;
use Nitro\View\Contracts\TemplateCompiler;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Whether compiled views are primed into opcache is decided by the environment
 * unless an application says otherwise.
 *
 * Priming belongs on in production and off in debug, and leaving that to a flag
 * every application has to remember means most of them ship with it off. Null —
 * the shipped default — means "decide"; an explicit true or false still wins.
 */
class CompiledTemplateCacheOpcacheTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/nitro_opcache_test_' . uniqid();
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/views/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tmp . '/views');
        @rmdir($this->tmp);
    }

    private function cache(mixed $useOpcache, bool $debug): CompiledTemplateCache
    {
        $paths = $this->createMock(PathRegistry::class);
        $paths->method('cache')->willReturn($this->tmp . '/views');

        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(fn ($key, $default = null) => match ($key) {
            'view.cache.enabled'     => true,
            'view.cache.expiry'      => 0,
            'view.cache.use_opcache' => $useOpcache,
            'view.cache.use_locks'   => false,
            'app.debug'              => $debug,
            default                  => $default,
        });

        return new CompiledTemplateCache($this->createMock(TemplateCompiler::class), $paths, $config);
    }

    private function useOpCache(CompiledTemplateCache $cache): bool
    {
        $property = new ReflectionProperty($cache, 'useOpCache');
        $property->setAccessible(true);

        return $property->getValue($cache);
    }

    public function test_it_primes_opcache_in_production_by_default(): void
    {
        $this->assertTrue($this->useOpCache($this->cache(null, debug: false)));
    }

    /** In debug, invalidating a template the developer just edited is what matters. */
    public function test_it_leaves_opcache_alone_in_debug_by_default(): void
    {
        $this->assertFalse($this->useOpCache($this->cache(null, debug: true)));
    }

    public function test_an_application_may_force_it_off_in_production(): void
    {
        $this->assertFalse($this->useOpCache($this->cache(false, debug: false)));
    }

    public function test_an_application_may_force_it_on_in_debug(): void
    {
        $this->assertTrue($this->useOpCache($this->cache(true, debug: true)));
    }
}
