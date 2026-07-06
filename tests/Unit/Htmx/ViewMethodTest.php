<?php

namespace Tests\Unit\Htmx;

use Nitro\Container\Container;
use Nitro\Foundation\Application;
use Nitro\Htmx\HtmxComponent;
use Nitro\Htmx\State\ArrayStateStore;
use Nitro\Htmx\State\StateStore;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the Livewire-style optional view() method on HtmxComponent.
 *
 * resolveDefaultView() cascade (first match wins):
 *   1. public function view(): string  — declarative binding
 *   2. protected ?string $view          — static override
 *   3. class-name convention            — Counter → components.htmx.counter
 */
class ViewMethodTest extends TestCase
{
    protected function setUp(): void
    {
        Container::reset();
        (new Application(dirname(__DIR__, 3)))->bootstrap();
        Container::getInstance()->singleton(StateStore::class, fn() => new ArrayStateStore());
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        Container::reset();
    }

    public function test_view_method_wins_over_property_and_convention(): void
    {
        $component = new class extends HtmxComponent {
            protected ?string $view = 'static.override';

            public function view(): string
            {
                return 'declared.via.method';
            }
        };

        $this->assertSame('declared.via.method', $component->resolveDefaultView());
    }

    public function test_view_property_wins_over_convention_when_no_method(): void
    {
        $component = new class extends HtmxComponent {
            protected ?string $view = 'static.override';
        };

        $this->assertSame('static.override', $component->resolveDefaultView());
    }

    public function test_convention_is_used_when_no_method_and_no_property(): void
    {
        $component = new MyConventionWidget();

        $this->assertSame(
            'components.htmx.my-convention-widget',
            $component->resolveDefaultView()
        );
    }

    public function test_view_method_can_compute_from_state(): void
    {
        $component = new class extends HtmxComponent {
            public bool $isAdmin = false;

            public function view(): string
            {
                return $this->isAdmin ? 'admin.dashboard' : 'user.dashboard';
            }
        };

        $this->assertSame('user.dashboard', $component->resolveDefaultView());
        $component->isAdmin = true;
        $this->assertSame('admin.dashboard', $component->resolveDefaultView());
    }

    public function test_auto_render_fallback_picks_up_view_method(): void
    {
        $component = new class extends HtmxComponent {
            public int $count = 0;

            public function view(): string
            {
                return 'computed.view.name';
            }
        };

        $component->applyAutoRenderFallback();

        $this->assertNotNull($component->renderContext);
        $this->assertSame('computed.view.name', $component->renderContext->view,
            'auto-render fallback should use the view() method declaration'
        );
    }
}

/**
 * Named class outside the test method so getShortName() returns a stable
 * value for the convention path test.
 */
class MyConventionWidget extends HtmxComponent
{
}
