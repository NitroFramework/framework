<?php

namespace Tests\Unit\View;

use Nitro\Foundation\Config;
use Nitro\Foundation\Contracts\PathRegistry;
use Nitro\Support\Opcache;
use Nitro\View\Compiler\CompiledTemplateCache;
use Nitro\View\Contracts\TemplateCompiler;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Compiled views are primed into opcache when the config asks for it and opcache is available.
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

    private function cache(bool $useOpcache): CompiledTemplateCache
    {
        $paths = $this->createMock(PathRegistry::class);
        $paths->method('cache')->willReturn($this->tmp . '/views');

        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(fn ($key, $default = null) => match ($key) {
            'view.cache.enabled'     => true,
            'view.cache.expiry'      => 0,
            'view.cache.use_opcache' => $useOpcache,
            'view.cache.use_locks'   => false,
            'app.debug'              => false,
            default                  => $default,
        });

        return new CompiledTemplateCache($this->createMock(TemplateCompiler::class), $paths, $config);
    }

    private function useOpCache(CompiledTemplateCache $cache): bool
    {
        return (new ReflectionProperty($cache, 'useOpCache'))->getValue($cache);
    }

    public function test_it_primes_opcache_when_asked_and_available(): void
    {
        $this->assertSame(Opcache::available(), $this->useOpCache($this->cache(true)));
    }

    public function test_an_application_may_turn_it_off(): void
    {
        $this->assertFalse($this->useOpCache($this->cache(false)));
    }

    public function test_availability_follows_the_running_sapi(): void
    {
        $setting = PHP_SAPI === 'cli' ? 'opcache.enable_cli' : 'opcache.enable';
        $expected = function_exists('opcache_compile_file')
            && filter_var(ini_get($setting), FILTER_VALIDATE_BOOL);

        $this->assertSame($expected, Opcache::available());
    }
}
