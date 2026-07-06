<?php

namespace Tests\Unit\View;

use Nitro\Foundation\Config;
use Nitro\Foundation\PathRegistry;
use Nitro\View\Compiler\CompiledTemplateCache;
use Nitro\View\Contracts\TemplateCompiler;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Confirms freshness verdicts are memoized for the request lifetime so that
 * rendering a view multiple times in the same request only stats the files
 * once.
 */
class CompiledTemplateCacheFreshnessTest extends TestCase
{
    protected string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/nitro_view_test_' . uniqid();
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->wipe($this->tmp);
    }

    protected function wipe(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $p = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($p) ? $this->wipe($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    protected function cache(): CompiledTemplateCache
    {
        $paths = $this->createMock(PathRegistry::class);
        $paths->method('cache')->willReturn($this->tmp . '/views');

        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(fn($k, $d = null) => match ($k) {
            'view.cache.enabled'      => true,
            'view.cache.expiry'       => 0,
            'view.cache.use_opcache'  => false,
            'view.cache.use_locks'    => false,
            default                   => $d,
        });

        $compiler = $this->createMock(TemplateCompiler::class);

        return new CompiledTemplateCache($compiler, $paths, $config);
    }

    public function test_first_call_populates_cache_and_subsequent_calls_reuse_verdict(): void
    {
        $cache = $this->cache();
        $template = $this->tmp . '/views/template.blade.php';
        $compiled = $this->tmp . '/views/compiled.php';
        @mkdir(dirname($template), 0755, true);
        file_put_contents($template, 'hello');
        file_put_contents($compiled, '<?php echo "hi";');

        $isFresh = new ReflectionMethod(CompiledTemplateCache::class, 'isFresh');

        $first  = $isFresh->invoke($cache, $template, $compiled);
        $second = $isFresh->invoke($cache, $template, $compiled);

        $this->assertTrue($first);
        $this->assertTrue($second);

        $memo = new ReflectionProperty(CompiledTemplateCache::class, 'freshnessCache');
        $stored = $memo->getValue($cache);
        $this->assertNotEmpty($stored, 'Freshness verdict must be memoized.');
    }

    public function test_clear_freshness_cache_drops_memoized_verdicts(): void
    {
        $cache = $this->cache();
        $template = $this->tmp . '/views/template.blade.php';
        $compiled = $this->tmp . '/views/compiled.php';
        @mkdir(dirname($template), 0755, true);
        file_put_contents($template, 'hello');
        file_put_contents($compiled, '<?php');

        $isFresh = new ReflectionMethod(CompiledTemplateCache::class, 'isFresh');
        $isFresh->invoke($cache, $template, $compiled);

        $cache->clearFreshnessCache();

        $memo = new ReflectionProperty(CompiledTemplateCache::class, 'freshnessCache');
        $this->assertSame([], $memo->getValue($cache));
    }

    public function test_missing_cache_file_returns_false(): void
    {
        $cache = $this->cache();
        $template = $this->tmp . '/views/exists.blade.php';
        @mkdir(dirname($template), 0755, true);
        file_put_contents($template, 'hi');

        $isFresh = new ReflectionMethod(CompiledTemplateCache::class, 'isFresh');
        $this->assertFalse($isFresh->invoke($cache, $template, $this->tmp . '/views/never.php'));
    }
}
