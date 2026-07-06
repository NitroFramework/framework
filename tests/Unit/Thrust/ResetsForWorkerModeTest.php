<?php

namespace Tests\Unit\Thrust;

use Nitro\Container\Container;
use Nitro\Foundation\Application;
use Nitro\Thrust\WorkerMode;
use PHPUnit\Framework\TestCase;

/**
 * The reset hook between worker iterations must:
 *   - drop the RESOLVED instance for request-scoped services so the next
 *     request re-resolves a fresh one
 *   - NOT remove the underlying binding (provider singletons must survive)
 *   - leave persistent services (router, view, config) entirely alone
 */
class ResetsForWorkerModeTest extends TestCase
{
    protected function setUp(): void
    {
        Container::reset();
    }

    public function test_reset_clears_resolved_instance_for_scoped_services(): void
    {
        $app = new Application(sys_get_temp_dir());
        $container = $app->getContainer();

        $container->singleton('auth', fn() => (object) ['ts' => microtime(true)]);
        $first = $container->get('auth');

        $app->resetForWorkerMode(new WorkerMode());

        $this->assertTrue($container->has('auth'), 'binding survives reset');
        $this->assertNotSame($first, $container->get('auth'), 'next get() re-resolves');
    }

    public function test_reset_leaves_persistent_services_alone(): void
    {
        $app = new Application(sys_get_temp_dir());
        $container = $app->getContainer();

        $container->singleton('router', fn() => new \stdClass());
        $first = $container->get('router');

        $app->resetForWorkerMode(new WorkerMode());

        $this->assertSame($first, $container->get('router'));
    }

    public function test_reset_honors_configured_scoped_list(): void
    {
        $app = new Application(sys_get_temp_dir());
        $container = $app->getContainer();

        $container->singleton('custom.scoped', fn() => (object) ['n' => mt_rand()]);
        $container->singleton('custom.persistent', fn() => (object) ['n' => mt_rand()]);

        $a1 = $container->get('custom.scoped');
        $p1 = $container->get('custom.persistent');

        $config = new WorkerMode();
        $config->scopedServices = ['custom.scoped'];
        $app->resetForWorkerMode($config);

        $this->assertNotSame($a1, $container->get('custom.scoped'));
        $this->assertSame($p1, $container->get('custom.persistent'));
    }

    public function test_reset_with_null_config_falls_back_to_sensible_defaults(): void
    {
        $app = new Application(sys_get_temp_dir());
        $container = $app->getContainer();

        $container->singleton('request', fn() => (object) ['stamp' => microtime(true)]);
        $first = $container->get('request');

        $app->resetForWorkerMode();

        $this->assertNotSame($first, $container->get('request'));
    }

    public function test_instance_re_bind_overrides_after_scoped_reset(): void
    {
        // Realistic worker-mode flow: scoped reset clears Request, Kernel
        // re-binds Request via instance() at the start of next iteration.
        $app = new Application(sys_get_temp_dir());
        $container = $app->getContainer();

        $container->instance('request', (object) ['id' => 1]);
        $this->assertSame(1, $container->get('request')->id);

        $app->resetForWorkerMode(new WorkerMode());

        $container->instance('request', (object) ['id' => 2]);
        $this->assertSame(2, $container->get('request')->id);
    }

    public function test_forget_scoped_hard_drops_binding_entirely(): void
    {
        $container = new Container();
        $container->singleton('throwaway', fn() => new \stdClass());
        $container->get('throwaway');

        $container->forgetScopedHard(['throwaway']);

        $this->assertFalse($container->has('throwaway'));
    }

    /**
     * Regression: the view renderer is a persistent singleton in worker
     * mode (its compiled-template cache survives across requests on
     * purpose), but per-render state — sections, stacks, fragments,
     * teleports — must be flushed between requests. Without this flush,
     * a `@push` from one request bleeds into a later request's `@stack`,
     * fragment lookups return stale content, etc. Symptom in the wild:
     * scalar-response actions in worker mode silently returned full
     * accumulated view markup because prior fragments were still resident.
     */
    public function test_reset_flushes_view_renderer_per_render_state(): void
    {
        // dirname(__DIR__, 3) = repo root — Application needs real config/
        // providers loaded so the view binding actually exists.
        $app = new Application(dirname(__DIR__, 3));
        $app->bootstrap();
        try {
            $this->driveViewLeakRegression($app);
        } finally {
            // Application::bootstrap() registers Nitro's error + exception
            // handlers. Restore them so PHPUnit's risky-test check stays quiet.
            restore_error_handler();
            restore_exception_handler();
        }
    }

    private function driveViewLeakRegression(Application $app): void
    {
        $container = $app->getContainer();

        // The 'view' alias points at the Blade facade; per-render state
        // lives on ViewRenderer, which the worker reset reaches for
        // directly. Mirror that here so the test exercises the same path.
        $this->assertTrue($container->has(\Nitro\View\Engine\ViewRenderer::class),
            'ViewRenderer singleton must be bound');
        $view = $container->get(\Nitro\View\Engine\ViewRenderer::class);
        $this->assertTrue(method_exists($view, 'flushState'),
            'view renderer must expose flushState()');

        // Dirty the renderer's three accumulation maps using public APIs.
        $view->forceSection('worker-test', '<p>section-leak</p>');

        $view->startPush('worker-test-stack');
        echo '<p>stack-leak</p>';
        $view->stopPush();

        // Fragments are protected and have no public setter beyond the
        // ob-based start/stop pair — use the start/stop pair so the test
        // matches the real capture path.
        $view->startFragment('worker-test-fragment');
        echo '<p>fragment-leak</p>';
        $view->stopFragment();

        // Confirm the dirt landed.
        $this->assertSame('<p>section-leak</p>', $view->getSection('worker-test'),
            'pre-reset: section should be present');
        $this->assertStringContainsString('stack-leak', $view->yieldStack('worker-test-stack'),
            'pre-reset: stack push should be present');
        $this->assertArrayHasKey('worker-test-fragment', $view->getFragments(),
            'pre-reset: fragment should be present');

        // The reset under test.
        $app->resetForWorkerMode(new WorkerMode());

        // After reset, all three maps must be empty.
        $this->assertSame('', $view->getSection('worker-test'),
            'post-reset: section must be gone');
        $this->assertSame('', $view->yieldStack('worker-test-stack'),
            'post-reset: stack must be gone');
        $this->assertSame([], $view->getFragments(),
            'post-reset: fragments map must be empty');

        // The singleton itself must survive — flushing state shouldn't
        // drop the binding (compiled-template cache lives on it).
        $this->assertSame($view, $container->get(\Nitro\View\Engine\ViewRenderer::class),
            'view renderer singleton must survive the reset');
    }
}
