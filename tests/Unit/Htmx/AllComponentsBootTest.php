<?php

namespace Tests\Unit\Htmx;

use Nitro\Container\Container;
use Nitro\Foundation\Application;
use Nitro\Htmx\HtmxComponent;
use Nitro\Htmx\State\ArrayStateStore;
use Nitro\Htmx\State\StateStore;
use PHPUnit\Framework\TestCase;

/**
 * Catch-all regression test: every HtmxComponent in app/Htmx/Components
 * must autoload, boot, and produce HTML without exploding. Targets the
 * "I refactored the trait and one component's action signature now
 * collides with the new trait method" class of bug — the kind that
 * passes feature-specific tests because the broken component wasn't
 * the one being tested.
 *
 * Runs across the actual file system, no allow-listing: any new
 * component added later is covered automatically.
 */
class AllComponentsBootTest extends TestCase
{
    protected function setUp(): void
    {
        Container::reset();
        (new Application(dirname(__DIR__, 3)))->bootstrap();

        $container = Container::getInstance();
        $container->singleton('request', fn() => new \Nitro\Http\Request(
            method: 'GET', path: '/test',
            headers: [], query: [], body: [], files: [], server: [],
        ));
        // Isolated store so the boot test doesn't litter session state.
        $container->singleton(StateStore::class, fn() => new ArrayStateStore());
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        Container::reset();
    }

    /**
     * Every concrete component class must instantiate + autoload cleanly.
     * Catches signature-collision fatals like a child class's action
     * shadowing a trait method with the wrong return type.
     */
    public function test_every_component_class_instantiates(): void
    {
        $broken = [];
        foreach ($this->discoverComponentClasses() as $class) {
            try {
                new $class();
            } catch (\Throwable $e) {
                $broken[] = "{$class}: " . get_class($e) . ' ' . $e->getMessage();
            }
        }

        $this->assertSame([], $broken,
            "Component classes that failed to instantiate:\n  - " . implode("\n  - ", $broken));
    }

    /**
     * Every component must boot through onBoot() without throwing.
     * Lifecycle changes (e.g. a new resolveX() that touches the request)
     * regress here even if the class file itself parses fine.
     */
    public function test_every_component_boots_through_onboot(): void
    {
        $broken = [];
        foreach ($this->discoverComponentClasses() as $class) {
            try {
                /** @var HtmxComponent $instance */
                $instance = new $class();
                $instance->onBoot();
            } catch (\Throwable $e) {
                $broken[] = "{$class}: " . get_class($e) . ' ' . $e->getMessage();
            }
        }

        $this->assertSame([], $broken,
            "Components that failed onBoot:\n  - " . implode("\n  - ", $broken));
    }

    /**
     * Every component's default view must exist and render. Catches:
     *   - view-name inference mismatch (file path drifts from kebab name)
     *   - Blade syntax errors in the view
     *   - missing variable bindings the view assumes
     */
    public function test_every_component_renders_via_widget_renderer(): void
    {
        $renderer = Container::getInstance()->make(\Nitro\Htmx\HtmxComponentRenderer::class);
        $broken = [];

        foreach ($this->discoverComponentClasses() as $class) {
            $short = (new \ReflectionClass($class))->getShortName();
            try {
                $html = $renderer->render($short);
                if ($html === '') {
                    // Empty output is suspicious — usually means renderView
                    // ended up null, which masks a real problem.
                    $broken[] = "{$short}: rendered empty string";
                }
            } catch (\Throwable $e) {
                $broken[] = "{$short}: " . get_class($e) . ' ' . $e->getMessage();
            }
        }

        $this->assertSame([], $broken,
            "Components that failed to render:\n  - " . implode("\n  - ", $broken));
    }

    /**
     * Walk app/Htmx/Components — same logic the obfuscator's
     * auto-discovery uses in production.
     */
    private function discoverComponentClasses(): array
    {
        $dir = dirname(__DIR__, 3) . '/app/Htmx/Components';
        $classes = [];
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $short = basename($file, '.php');
            $fqcn = 'App\\Htmx\\Components\\' . $short;
            if (class_exists($fqcn) && is_subclass_of($fqcn, HtmxComponent::class)) {
                $classes[] = $fqcn;
            }
        }
        sort($classes);
        return $classes;
    }
}
