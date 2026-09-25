<?php

namespace Tests\Unit\View;

use Nitro\Container\Container;
use Nitro\Container\ContainerClassResolver;
use Nitro\Events\Dispatcher;
use Nitro\Foundation\Config;
use Nitro\Foundation\Contracts\PathRegistry;
use Nitro\View\Compiler\BladeCompiler;
use Nitro\View\Compiler\CompiledTemplateCache;
use Nitro\View\Compiler\ComponentTagCompiler;
use Nitro\View\Component\ComponentRenderer;
use Nitro\View\Engines\CompilerEngine;
use Nitro\View\Factory;
use Nitro\View\Support\ComposerResolver;
use Nitro\View\View;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Composers and creators, carried on the event bus.
 *
 * They used to be a list the factory walked, matching only '*', an exact name
 * or 'prefix.*', and firing only for the view a controller chose — a composer
 * registered for a partial or a layout never ran. These cover the event names,
 * full wildcard patterns, class composers and creators, and the views a page
 * pulls in.
 */
class ViewComposerTest extends TestCase
{
    private string $storageDir;

    private string $viewsDir;

    private CompilerEngine $engine;

    private Dispatcher $events;

    private Factory $factory;

    protected function setUp(): void
    {
        $this->storageDir = dirname(__DIR__) . '/storage/tests_composers';
        $this->viewsDir = $this->storageDir . '/views';

        @mkdir($this->viewsDir, 0777, true);
        @mkdir($this->storageDir . '/cache', 0777, true);

        $container = new Container();
        $resolver = new ContainerClassResolver($container);

        $this->events = new Dispatcher();
        $this->events->setContainer($container);

        $this->engine = $this->buildEngine();
        $this->factory = new Factory($this->engine, new ComposerResolver($this->events, $resolver));

        // As the view provider wires it.
        $this->engine->prepareNestedViewsWith(fn (string $view, array $data): array => $this->factory->composed($view, $data));
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->storageDir);
    }

    // ─── Composers ────────────────────────────────────────

    public function test_a_composer_adds_data_before_the_view_renders(): void
    {
        $this->view('orders.show', 'Order {{ $number }}');

        $this->factory->composer('orders.show', fn (View $view) => $view->with('number', 42));

        $this->assertSame('Order 42', $this->render('orders.show'));
    }

    public function test_a_composer_is_a_listener_for_composing_the_view(): void
    {
        $this->view('orders.show', '{{ $number }}');

        $this->events->listen('composing: orders.show', fn (View $view) => $view->with('number', 7));

        $this->assertSame('7', $this->render('orders.show'));
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function patterns(): array
    {
        return [
            'prefix'            => ['admin.*', 'admin.users.index', true],
            'suffix'            => ['*.index', 'admin.users.index', true],
            'middle'            => ['admin.*.index', 'admin.users.index', true],
            'everything'        => ['*', 'admin.users.index', true],
            'exact'             => ['admin.users.index', 'admin.users.index', true],
            'prefix, elsewhere' => ['admin.*', 'shop.index', false],
            'suffix, elsewhere' => ['*.index', 'admin.users.show', false],
        ];
    }

    #[DataProvider('patterns')]
    public function test_a_composer_matches_by_wildcard(string $pattern, string $view, bool $matches): void
    {
        $this->view($view, '{{ $seen ?? "no" }}');

        $this->factory->composer($pattern, fn (View $v) => $v->with('seen', 'yes'));

        $this->assertSame($matches ? 'yes' : 'no', $this->render($view));
    }

    public function test_a_slash_in_a_view_name_reads_as_a_dot(): void
    {
        $this->view('orders.show', '{{ $number }}');

        $this->factory->composer('orders/show', fn (View $view) => $view->with('number', 3));

        $this->assertSame('3', $this->render('orders.show'));
    }

    public function test_a_class_composer_is_built_and_composes(): void
    {
        $this->view('orders.show', '{{ $source }}');

        $this->factory->composer('orders.show', OrderComposer::class);

        $this->assertSame('compose', $this->render('orders.show'));
    }

    public function test_a_class_composer_can_name_its_method(): void
    {
        $this->view('orders.show', '{{ $source }}');

        $this->factory->composer('orders.show', OrderComposer::class . '@summary');

        $this->assertSame('summary', $this->render('orders.show'));
    }

    public function test_composers_registers_several_at_once(): void
    {
        $this->view('orders.show', '{{ $source }}');
        $this->view('orders.index', '{{ $source }}');

        $registered = $this->factory->composers([
            OrderComposer::class => ['orders.show', 'orders.index'],
        ]);

        $this->assertCount(2, $registered);
        $this->assertSame('compose', $this->render('orders.show'));
        $this->assertSame('compose', $this->render('orders.index'));
    }

    public function test_composer_returns_a_listener_for_each_view(): void
    {
        $registered = $this->factory->composer(['a', 'b', 'c'], fn () => null);

        $this->assertCount(3, $registered);
    }

    // ─── Creators ─────────────────────────────────────────

    public function test_a_creator_runs_when_the_view_is_made(): void
    {
        $made = null;

        $this->factory->creator('orders.show', function (View $view) use (&$made): void {
            $made = $view->name();
        });

        $this->factory->make('orders.show');

        $this->assertSame('orders.show', $made);
    }

    /**
     * A creator runs before the caller's with(), and a composer after it — so
     * the caller overrides a creator, and a composer overrides the caller.
     */
    public function test_creators_run_before_the_caller_and_composers_after(): void
    {
        $this->view('orders.show', '{{ $created }} {{ $composed }}');

        $this->factory->creator('orders.show', fn (View $v) => $v->with('created', 'creator')->with('composed', 'creator'));
        $this->factory->composer('orders.show', fn (View $v) => $v->with('composed', 'composer'));

        $html = $this->factory->make('orders.show')
            ->with('created', 'caller')
            ->with('composed', 'caller')
            ->render();

        $this->assertSame('caller composer', $html);
    }

    public function test_a_class_creator_is_built_and_creates(): void
    {
        $this->view('orders.show', '{{ $source }}');

        $this->factory->creator('orders.show', OrderComposer::class);

        $this->assertSame('create', $this->render('orders.show'));
    }

    public function test_a_creator_matches_by_wildcard(): void
    {
        $this->view('admin.users.index', '{{ $seen }}');

        $this->factory->creator('*.index', fn (View $v) => $v->with('seen', 'created'));

        $this->assertSame('created', $this->render('admin.users.index'));
    }

    // ─── Views a page pulls in ────────────────────────────

    /** The defect: a composer registered for a partial never ran. */
    public function test_a_composer_runs_for_an_included_view(): void
    {
        $this->view('partials.nav', '<nav>{{ $links }}</nav>');
        $this->view('pages.home', "@include('partials.nav')");

        $this->factory->composer('partials.nav', fn (View $view) => $view->with('links', 'home|about'));

        $this->assertSame('<nav>home|about</nav>', trim($this->render('pages.home')));
    }

    public function test_a_composer_runs_for_a_layout(): void
    {
        $this->view('layouts.app', "<title>{{ \$appName }}</title>@yield('content')");
        $this->view('pages.home', "@extends('layouts.app')\n@section('content')\nHome\n@endsection");

        $this->factory->composer('layouts.app', fn (View $view) => $view->with('appName', 'Nitro'));

        $this->assertSame('<title>Nitro</title>Home', preg_replace('/\s+/', '', $this->render('pages.home')));
    }

    public function test_a_composer_runs_for_a_component_view(): void
    {
        $this->view('components.badge', '<b>{{ $count }}</b>');
        $this->view('pages.home', '<x-badge />');

        $this->factory->composer('components.badge', fn (View $view) => $view->with('count', 9));

        $this->assertSame('<b>9</b>', trim($this->render('pages.home')));
    }

    public function test_a_composer_runs_for_a_partial_rendered_directly(): void
    {
        $this->view('partials.nav', '{{ $links }}');

        $this->factory->composer('partials.nav', fn (View $view) => $view->with('links', 'direct'));

        $this->assertSame('direct', $this->factory->renderPartial('partials.nav'));
    }

    /** Composed once, not once by the factory and again by the engine. */
    public function test_the_chosen_view_is_composed_once(): void
    {
        $this->view('pages.home', 'home');

        $calls = 0;

        $this->factory->composer('pages.home', function () use (&$calls): void {
            $calls++;
        });

        $this->render('pages.home');

        $this->assertSame(1, $calls);
    }

    public function test_an_included_view_nobody_composes_renders_as_before(): void
    {
        $this->view('partials.nav', '{{ $links }}');
        $this->view('pages.home', "@include('partials.nav', ['links' => 'given'])");

        $this->factory->composer('something.else', fn (View $view) => $view->with('links', 'wrong'));

        $this->assertSame('given', trim($this->render('pages.home')));
    }

    // ─── Shared data ──────────────────────────────────────

    public function test_share_takes_several_values_as_an_array(): void
    {
        $this->view('pages.home', '{{ $a }}{{ $b }}');

        $this->factory->share(['a' => 1, 'b' => 2]);

        $this->assertSame('12', $this->render('pages.home'));
        $this->assertSame(1, $this->factory->shared('a'));
        $this->assertSame('none', $this->factory->shared('missing', 'none'));
    }

    // ─── Helpers ──────────────────────────────────────────

    private function render(string $view): string
    {
        return $this->factory->make($view)->render();
    }

    private function view(string $name, string $blade): void
    {
        $file = $this->viewsDir . '/' . str_replace('.', '/', $name) . '.blade.php';

        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, $blade);
    }

    private function buildEngine(): CompilerEngine
    {
        $tagCompiler = new ComponentTagCompiler();
        $compiler = new BladeCompiler($tagCompiler);

        $paths = $this->createMock(PathRegistry::class);
        $paths->method('views')->willReturn($this->viewsDir);
        $paths->method('storage')->willReturn($this->storageDir . '/cache');

        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(fn ($key, $default = null) => match ($key) {
            'view.extension'  => 'blade.php',
            'view.cache_path' => $this->storageDir . '/cache',
            'app.debug'       => false,
            default           => $default,
        });

        return new CompilerEngine(
            new CompiledTemplateCache($compiler, $paths, $config),
            new ComponentRenderer(fn () => $this->engine),
            $compiler,
            $tagCompiler,
            $paths,
            $config,
        );
    }

    private function deleteDir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;
            is_dir($full) ? $this->deleteDir($full) : unlink($full);
        }

        rmdir($path);
    }
}

class OrderComposer
{
    public function compose(View $view): void
    {
        $view->with('source', 'compose');
    }

    public function summary(View $view): void
    {
        $view->with('source', 'summary');
    }

    public function create(View $view): void
    {
        $view->with('source', 'create');
    }
}
