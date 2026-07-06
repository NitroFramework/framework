<?php

namespace Tests\Unit\View;

use Nitro\Foundation\Config;
use Nitro\Foundation\PathRegistry;
use Nitro\View\Compiler\BladeCompiler;
use Nitro\View\Compiler\CompiledTemplateCache;
use Nitro\View\Compiler\ComponentTagCompiler;
use Nitro\View\Component\ComponentRenderer;
use Nitro\View\Engine\ViewRenderer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The renderer is a long-lived singleton and only flushes render state at the
 * top level (renderCount === 0). If a template throws mid-render and the depth
 * counter isn't balanced, the NEXT request's top-level render looks nested and
 * skips flushState() — leaking sections/stacks across requests. The try/finally
 * in the render methods must keep renderCount balanced through exceptions.
 */
class RenderDepthLeakTest extends TestCase
{
    private ViewRenderer $engine;
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = dirname(__DIR__) . '/storage/tests_depth';
        @mkdir($this->dir . '/views', 0777, true);
        @mkdir($this->dir . '/cache', 0777, true);
        $this->engine = $this->buildEngine();
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getRealPath()) : @unlink($f->getRealPath());
        }
        @rmdir($this->dir);
    }

    private function renderCount(): int
    {
        $ctx = (new ReflectionProperty(ViewRenderer::class, 'context'))->getValue($this->engine);
        return $ctx->renderCount;
    }

    private function makeView(string $name, string $blade): void
    {
        file_put_contents($this->dir . '/views/' . $name . '.blade.php', $blade);
    }

    public function test_depth_is_balanced_after_a_template_throws(): void
    {
        $this->makeView('boom', '@php throw new \RuntimeException("boom"); @endphp');

        $this->assertSame(0, $this->renderCount(), 'precondition');

        try {
            $this->engine->render('boom');
            $this->fail('expected the template to throw');
        } catch (\Throwable $e) {
            // swallowed — the point is what happens to renderCount
        }

        $this->assertSame(0, $this->renderCount(), 'renderCount must return to 0 after an exception');
    }

    public function test_next_render_after_a_failure_still_works(): void
    {
        $this->makeView('boom', '@php throw new \RuntimeException("boom"); @endphp');
        $this->makeView('ok', 'Hello {{ $name }}');

        try {
            $this->engine->render('boom');
        } catch (\Throwable) {
        }

        // Because depth was balanced, this is correctly detected as top-level
        // and renders cleanly rather than inheriting stale nested state.
        $this->assertSame('Hello World', $this->engine->render('ok', ['name' => 'World']));
        $this->assertSame(0, $this->renderCount());
    }

    private function buildEngine(): ViewRenderer
    {
        $tagCompiler = new ComponentTagCompiler();
        $compiler = new BladeCompiler($tagCompiler);

        $paths = $this->createMock(PathRegistry::class);
        $paths->method('views')->willReturn($this->dir . '/views');
        $paths->method('storage')->willReturn($this->dir . '/cache');

        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(fn($key, $default = null) => match ($key) {
            'view.extension'  => 'blade.php',
            'view.cache_path' => $this->dir . '/cache',
            'app.debug'       => false,
            default           => $default,
        });

        $templateCache = new CompiledTemplateCache($compiler, $paths, $config);
        $components = new ComponentRenderer(fn() => $this->engine);

        return new ViewRenderer($templateCache, $components, $compiler, $tagCompiler, $paths, $config);
    }
}
