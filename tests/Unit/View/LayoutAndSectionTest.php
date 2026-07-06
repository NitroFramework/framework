<?php

namespace Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use Nitro\View\Engine\ViewRenderer;
use Nitro\View\Compiler\CompiledTemplateCache;
use Nitro\View\Compiler\BladeCompiler;
use Nitro\Foundation\PathRegistry;
use Nitro\Foundation\Config;

class LayoutAndSectionTest extends TestCase
{
    private ViewRenderer $engine;
    private string $storageDir;
    private string $viewsDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->storageDir = dirname(__DIR__) . '/storage/tests_layout';
        $this->viewsDir   = $this->storageDir . '/views';
        $this->cacheDir   = $this->storageDir . '/cache';

        $this->ensureDir($this->viewsDir . '/layouts');
        $this->ensureDir($this->viewsDir . '/pages');
        $this->ensureDir($this->viewsDir . '/components');
        $this->ensureDir($this->cacheDir);

        $this->engine = $this->buildEngine();
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->storageDir);
    }

    private function buildEngine(): ViewRenderer
    {
        $tagCompiler = new \Nitro\View\Compiler\ComponentTagCompiler();
        $compiler = new BladeCompiler($tagCompiler);

        $paths = $this->createMock(PathRegistry::class);
        $paths->method('views')->willReturn($this->viewsDir);
        $paths->method('storage')->willReturn($this->cacheDir);

        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(fn ($key, $default = null) => match ($key) {
            'view.extension'  => 'blade.php',
            'view.cache_path' => $this->cacheDir,
            'app.debug'       => false,
            default           => $default,
        });

        $templateCache = new CompiledTemplateCache($compiler, $paths, $config);

        $components = new \Nitro\View\Component\ComponentRenderer(
            fn() => $this->engine,
        );

        return new ViewRenderer(
            $templateCache,
            $components,
            $compiler,
            $tagCompiler,
            $paths,
            $config
        );
    }

    private function makeView(string $name, string $blade): void
    {
        $path = str_replace('.', DIRECTORY_SEPARATOR, $name);
        $file = $this->viewsDir . '/' . $path . '.blade.php';
        $this->ensureDir(dirname($file));
        file_put_contents($file, $blade);
    }

    private function render(string $view, array $data = []): string
    {
        return $this->engine->render($view, $data);
    }

    private function ensureDir(string $path): void
    {
        if (!is_dir($path)) mkdir($path, 0777, true);
    }

    private function deleteDir(string $path): void
    {
        if (!is_dir($path)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($path);
    }

    // -----------------------------------------------------------------------
    // SECTIONS: Basic @extends / @section / @yield
    // -----------------------------------------------------------------------

    public function test_basic_extends_and_yield(): void
    {
        $this->makeView('layouts.main', '
            <html><body>@yield("content")</body></html>
        ');

        $this->makeView('pages.home', '
            @extends("layouts.main")
            @section("content")
                <h1>Home Page</h1>
            @endsection
        ');

        $out = $this->render('pages.home');

        $this->assertStringContainsString('<html>', $out);
        $this->assertStringContainsString('<h1>Home Page</h1>', $out);
        $this->assertStringContainsString('</body></html>', $out);
    }

    public function test_yield_with_default_value(): void
    {
        $this->makeView('layouts.main', '
            <title>@yield("title", "Default Title")</title>
            <body>@yield("content")</body>
        ');

        $this->makeView('pages.no_title', '
            @extends("layouts.main")
            @section("content")
                <p>Body only</p>
            @endsection
        ');

        $out = $this->render('pages.no_title');

        $this->assertStringContainsString('Default Title', $out);
        $this->assertStringContainsString('Body only', $out);
    }

    public function test_yield_default_overridden_by_child(): void
    {
        $this->makeView('layouts.main', '
            <title>@yield("title", "Default")</title>
            <body>@yield("content")</body>
        ');

        $this->makeView('pages.with_title', '
            @extends("layouts.main")
            @section("title", "Custom Title")
            @section("content")
                <p>Content</p>
            @endsection
        ');

        $out = $this->render('pages.with_title');

        $this->assertStringContainsString('Custom Title', $out);
        $this->assertStringNotContainsString('Default', $out);
    }

    public function test_multiple_sections(): void
    {
        $this->makeView('layouts.main', '
            <head>@yield("head")</head>
            <body>
                <nav>@yield("nav")</nav>
                <main>@yield("content")</main>
                <footer>@yield("footer")</footer>
            </body>
        ');

        $this->makeView('pages.full', '
            @extends("layouts.main")
            @section("head")
                <title>Full Page</title>
            @endsection
            @section("nav")
                <a href="/">Home</a>
            @endsection
            @section("content")
                <h1>Main Content</h1>
            @endsection
            @section("footer")
                <p>Footer Text</p>
            @endsection
        ');

        $out = $this->render('pages.full');

        $this->assertStringContainsString('<title>Full Page</title>', $out);
        $this->assertStringContainsString('<a href="/">Home</a>', $out);
        $this->assertStringContainsString('<h1>Main Content</h1>', $out);
        $this->assertStringContainsString('<p>Footer Text</p>', $out);
    }

    // -----------------------------------------------------------------------
    // SECTIONS: Inline @section('key', 'value')
    // -----------------------------------------------------------------------

    public function test_inline_section(): void
    {
        $this->makeView('layouts.main', '
            <title>@yield("title")</title>
            <body>@yield("content")</body>
        ');

        $this->makeView('pages.inline', '
            @extends("layouts.main")
            @section("title", "Inline Title")
            @section("content")
                <p>Body</p>
            @endsection
        ');

        $out = $this->render('pages.inline');

        $this->assertStringContainsString('Inline Title', $out);
        $this->assertStringContainsString('Body', $out);
    }

    // -----------------------------------------------------------------------
    // SECTIONS: @parent placeholder
    // -----------------------------------------------------------------------

    public function test_parent_injects_parent_content(): void
    {
        $this->makeView('layouts.main', '
            <body>
            @section("sidebar")
                <p>Parent Sidebar</p>
            @show
            </body>
        ');

        $this->makeView('pages.child', '
            @extends("layouts.main")
            @section("sidebar")
                @parent
                <p>Child Sidebar</p>
            @endsection
        ');

        $out = $this->render('pages.child');

        $this->assertStringContainsString('Parent Sidebar', $out);
        $this->assertStringContainsString('Child Sidebar', $out);

        // Parent content should come before child content
        $this->assertLessThan(
            strpos($out, 'Child Sidebar'),
            strpos($out, 'Parent Sidebar')
        );
    }

    public function test_parent_wraps_parent_content(): void
    {
        $this->makeView('layouts.main', '
            @section("content")
                <p>Original</p>
            @show
        ');

        $this->makeView('pages.wrapper', '
            @extends("layouts.main")
            @section("content")
                <div class="wrapper">
                    @parent
                </div>
                <p>Extra</p>
            @endsection
        ');

        $out = $this->render('pages.wrapper');

        $this->assertStringContainsString('Original', $out);
        $this->assertStringContainsString('Extra', $out);
        $this->assertStringContainsString('class="wrapper"', $out);
    }

    public function test_parent_without_existing_section_outputs_nothing(): void
    {
        $this->makeView('layouts.main', '
            <body>@yield("content")</body>
        ');

        $this->makeView('pages.orphan_parent', '
            @extends("layouts.main")
            @section("content")
                @parent
                <p>Child Only</p>
            @endsection
        ');

        $out = $this->render('pages.orphan_parent');

        $this->assertStringContainsString('Child Only', $out);
        // No placeholder tokens should leak into output
        $this->assertStringNotContainsString('##parent-placeholder', $out);
    }

    // -----------------------------------------------------------------------
    // SECTIONS: @show
    // -----------------------------------------------------------------------

    public function test_show_renders_section_inline(): void
    {
        $this->makeView('layouts.main', '
            <body>
            @section("sidebar")
                <p>Default Sidebar</p>
            @show
            <main>@yield("content")</main>
            </body>
        ');

        $this->makeView('pages.no_override', '
            @extends("layouts.main")
            @section("content")
                <p>Page Content</p>
            @endsection
        ');

        $out = $this->render('pages.no_override');

        $this->assertStringContainsString('Default Sidebar', $out);
        $this->assertStringContainsString('Page Content', $out);
    }

    // -----------------------------------------------------------------------
    // SECTIONS: @overwrite
    // -----------------------------------------------------------------------

    public function test_overwrite_replaces_section(): void
    {
        $this->makeView('layouts.main', '
            @section("sidebar")
                <p>Parent Sidebar</p>
            @show
            @yield("content")
        ');

        $this->makeView('pages.overwrite', '
            @extends("layouts.main")
            @section("sidebar")
                <p>Completely New Sidebar</p>
            @overwrite
            @section("content")
                <p>Content</p>
            @endsection
        ');

        $out = $this->render('pages.overwrite');

        $this->assertStringContainsString('Completely New Sidebar', $out);
        $this->assertStringNotContainsString('Parent Sidebar', $out);
    }

    // -----------------------------------------------------------------------
    // SECTIONS: @append
    // -----------------------------------------------------------------------

    public function test_append_adds_to_section(): void
    {
        $this->makeView('layouts.main', '
            @section("sidebar")
                <p>Base</p>
            @show
        ');

        $this->makeView('pages.appender', '
            @extends("layouts.main")
            @section("sidebar")
                <p>Appended</p>
            @append
        ');

        $out = $this->render('pages.appender');

        $this->assertStringContainsString('Base', $out);
        $this->assertStringContainsString('Appended', $out);
    }

    // -----------------------------------------------------------------------
    // SECTIONS: @hasSection / @sectionMissing
    // -----------------------------------------------------------------------

    public function test_has_section_true(): void
    {
        $this->makeView('layouts.main', '
            @hasSection("sidebar")
                <p>Has Sidebar</p>
            @endif
            @yield("content")
        ');

        $this->makeView('pages.with_sidebar', '
            @extends("layouts.main")
            @section("sidebar")
                Sidebar content
            @endsection
            @section("content")
                Main
            @endsection
        ');

        $out = $this->render('pages.with_sidebar');

        $this->assertStringContainsString('Has Sidebar', $out);
    }

    public function test_has_section_false(): void
    {
        $this->makeView('layouts.main', '
            @hasSection("sidebar")
                <p>Has Sidebar</p>
            @endif
            @yield("content")
        ');

        $this->makeView('pages.no_sidebar', '
            @extends("layouts.main")
            @section("content")
                Main only
            @endsection
        ');

        $out = $this->render('pages.no_sidebar');

        $this->assertStringNotContainsString('Has Sidebar', $out);
        $this->assertStringContainsString('Main only', $out);
    }

    // -----------------------------------------------------------------------
    // STACKS: @push / @prepend / @stack
    // -----------------------------------------------------------------------

    public function test_push_and_stack_in_layout(): void
    {
        $this->makeView('layouts.main', '
            <head>@stack("styles")</head>
            <body>@yield("content")</body>
            @stack("scripts")
        ');

        $this->makeView('pages.with_stacks', '
            @extends("layouts.main")
            @push("styles")
                <link rel="stylesheet" href="app.css">
            @endpush
            @push("scripts")
                <script src="app.js"></script>
            @endpush
            @section("content")
                <p>Page</p>
            @endsection
        ');

        $out = $this->render('pages.with_stacks');

        $this->assertStringContainsString('app.css', $out);
        $this->assertStringContainsString('app.js', $out);
        $this->assertStringContainsString('Page', $out);
    }

    public function test_multiple_pushes_to_same_stack(): void
    {
        $this->makeView('layouts.main', '
            @stack("scripts")
        ');

        $this->makeView('pages.multi_push', '
            @extends("layouts.main")
            @push("scripts")
                <script>first();</script>
            @endpush
            @push("scripts")
                <script>second();</script>
            @endpush
        ');

        $out = $this->render('pages.multi_push');

        $this->assertStringContainsString('first()', $out);
        $this->assertStringContainsString('second()', $out);

        // Push order preserved
        $this->assertLessThan(
            strpos($out, 'second()'),
            strpos($out, 'first()')
        );
    }

    public function test_prepend_goes_before_push(): void
    {
        $this->makeView('layouts.main', '
            @stack("scripts")
        ');

        $this->makeView('pages.prepend_push', '
            @extends("layouts.main")
            @push("scripts")
                <script>pushed();</script>
            @endpush
            @prepend("scripts")
                <script>prepended();</script>
            @endprepend
        ');

        $out = $this->render('pages.prepend_push');

        $this->assertStringContainsString('pushed()', $out);
        $this->assertStringContainsString('prepended()', $out);

        // Prepend comes first
        $this->assertLessThan(
            strpos($out, 'pushed()'),
            strpos($out, 'prepended()')
        );
    }

    public function test_empty_stack_renders_nothing(): void
    {
        $this->makeView('layouts.main', '
            <head>@stack("styles")</head>
        ');

        $this->makeView('pages.no_push', '
            @extends("layouts.main")
        ');

        $out = $this->render('pages.no_push');

        $this->assertStringContainsString('<head>', $out);
        // No leftover markers
        $this->assertStringNotContainsString('@stack', $out);
    }

    // -----------------------------------------------------------------------
    // FRAGMENTS: @fragment / @endfragment
    // -----------------------------------------------------------------------

    public function test_fragment_renders_in_full_output(): void
    {
        $this->makeView('pages.fragmented', '
            <p>Before</p>
            @fragment("piece")
                <p>Fragment Content</p>
            @endfragment
            <p>After</p>
        ');

        $out = $this->render('pages.fragmented');

        $this->assertStringContainsString('Before', $out);
        $this->assertStringContainsString('Fragment Content', $out);
        $this->assertStringContainsString('After', $out);
    }

    public function test_render_single_fragment(): void
    {
        $this->makeView('pages.fragmented', '
            <p>Before</p>
            @fragment("piece")
                <p>Fragment Content</p>
            @endfragment
            <p>After</p>
        ');

        $out = $this->engine->renderFragment('pages.fragmented', 'piece');

        $this->assertStringContainsString('Fragment Content', $out);
    }

    public function test_render_fragment_throws_if_not_found(): void
    {
        $this->makeView('pages.fragmented', '
            <p>No fragments here</p>
        ');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Fragment 'missing' not found");

        $this->engine->renderFragment('pages.fragmented', 'missing');
    }

    public function test_multiple_fragments_in_same_view(): void
    {
        $this->makeView('pages.multi_frag', '
            @fragment("header")
                <h1>Header</h1>
            @endfragment
            <p>Middle</p>
            @fragment("footer")
                <p>Footer</p>
            @endfragment
        ');

        $header = $this->engine->renderFragment('pages.multi_frag', 'header');
        $footer = $this->engine->renderFragment('pages.multi_frag', 'footer');

        $this->assertStringContainsString('Header', $header);
        $this->assertStringNotContainsString('Footer', $header);

        $this->assertStringContainsString('Footer', $footer);
        $this->assertStringNotContainsString('Header', $footer);
    }

    public function test_fragment_with_dynamic_data(): void
    {
        $this->makeView('pages.dynamic_frag', '
            @fragment("greeting")
                <p>Hello {{ $name }}</p>
            @endfragment
        ');

        $out = $this->engine->renderFragment('pages.dynamic_frag', 'greeting', ['name' => 'Mirza']);

        $this->assertStringContainsString('Hello Mirza', $out);
    }

    public function test_render_fragments_returns_multiple(): void
    {
        $this->makeView('pages.oob', '
            @fragment("main")
                <div id="main">Main Content</div>
            @endfragment
            @fragment("sidebar")
                <div id="sidebar">Sidebar Content</div>
            @endfragment
        ');

        $out = $this->engine->renderFragments('pages.oob', ['main', 'sidebar']);

        $this->assertStringContainsString('Main Content', $out);
        $this->assertStringContainsString('Sidebar Content', $out);
        // Second fragment should get hx-swap-oob
        $this->assertStringContainsString('hx-swap-oob="true"', $out);
    }

    // -----------------------------------------------------------------------
    // SECTIONS: State isolation between renders
    // -----------------------------------------------------------------------

    public function test_sections_dont_bleed_between_renders(): void
    {
        $this->makeView('layouts.main', '
            <title>@yield("title", "Default")</title>
            <body>@yield("content")</body>
        ');

        $this->makeView('pages.first', '
            @extends("layouts.main")
            @section("title", "First Page")
            @section("content")
                <p>First</p>
            @endsection
        ');

        $this->makeView('pages.second', '
            @extends("layouts.main")
            @section("title", "Second Page")
            @section("content")
                <p>Second</p>
            @endsection
        ');

        $out1 = $this->render('pages.first');
        $out2 = $this->render('pages.second');

        $this->assertStringContainsString('First Page', $out1);
        $this->assertStringNotContainsString('Second', $out1);

        $this->assertStringContainsString('Second Page', $out2);
        $this->assertStringNotContainsString('First', $out2);
    }

    public function test_stacks_dont_bleed_between_renders(): void
    {
        $this->makeView('layouts.main', '@stack("scripts")');

        $this->makeView('pages.first', '
            @extends("layouts.main")
            @push("scripts")
                <script>first();</script>
            @endpush
        ');

        $this->makeView('pages.second', '
            @extends("layouts.main")
            @push("scripts")
                <script>second();</script>
            @endpush
        ');

        $out1 = $this->render('pages.first');
        $out2 = $this->render('pages.second');

        $this->assertStringContainsString('first()', $out1);
        $this->assertStringNotContainsString('second()', $out1);

        $this->assertStringContainsString('second()', $out2);
        $this->assertStringNotContainsString('first()', $out2);
    }
}
