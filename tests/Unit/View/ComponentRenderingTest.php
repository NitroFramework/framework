<?php

namespace Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use Nitro\View\Engine\ViewRenderer;
use Nitro\View\Compiler\CompiledTemplateCache;
use Nitro\View\Compiler\BladeCompiler;
use Nitro\Foundation\PathRegistry;
use Nitro\Foundation\Config;

class ComponentRenderingTest extends TestCase
{
    private ViewRenderer $engine;
    private string $storageDir;
    private string $viewsDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        // Use storage folder inside your project
        $this->storageDir = dirname(__DIR__) . '/storage/tests';
        $this->viewsDir   = $this->storageDir . '/views';
        $this->cacheDir   = $this->storageDir . '/cache';

        $this->ensureDir($this->viewsDir . '/components');
        $this->ensureDir($this->cacheDir);

        $this->engine = $this->buildEngine();
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->storageDir);
    }

    // -----------------------------------------------------------------------
    // Engine factory
    // -----------------------------------------------------------------------

    private function buildEngine(): ViewRenderer
    {
        // 1. Core logic dependencies
        $tagCompiler = new \Nitro\View\Compiler\ComponentTagCompiler();
        $compiler = new BladeCompiler($tagCompiler);


        // 2. Mocks
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

        // 3. The Cache
        $templateCache = new CompiledTemplateCache(
            $compiler,
            $paths,
            $config
        );

        // 4. Component Renderer — no more SectionManager
        $components = new \Nitro\View\Component\ComponentRenderer(
            fn() => $this->engine,
        );

        // 5. Renderer — no more SectionManager
        return new ViewRenderer(
            $templateCache,
            $components,
            $compiler,
            $tagCompiler,
            $paths,
            $config
        );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeComponent(string $name, string $blade): void
    {
        // Convert dot notation to path: forms.input → components/forms/input
        $path = str_replace('.', DIRECTORY_SEPARATOR, $name);
        $file = $this->viewsDir . '/components/' . $path . '.blade.php';
        $this->ensureDir(dirname($file));
        file_put_contents($file, $blade);
    }

    private function makeView(string $name, string $blade): void
    {
        $path = str_replace('.', DIRECTORY_SEPARATOR, $name);
        $file = $this->viewsDir . '/' . $path . '.blade.php';
        $this->ensureDir(dirname($file));
        file_put_contents($file, $blade);
    }

    private function render(string $blade, array $data = []): string
    {
        return $this->engine->renderString($blade, $data);
    }

    private function renderView(string $view, array $data = []): string
    {
        return $this->engine->render($view, $data);
    }

    private function ensureDir(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
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

    private function assertHtml(string $expected, string $actual): void
    {
        // Strip whitespace noise for cleaner assertions
        $clean = fn(string $s) => preg_replace('/\s+/', ' ', trim(strip_tags($s, '<div><span><p><ul><li><strong><em><button><input><form><label><h1><h2><h3>')));
        $this->assertStringContainsString(
            preg_replace('/\s+/', ' ', trim($expected)),
            preg_replace('/\s+/', ' ', trim($actual))
        );
    }

    // -----------------------------------------------------------------------
    // 1. SELF-CLOSING — plain attributes
    // -----------------------------------------------------------------------

    public function test_self_closing_with_plain_string_attribute(): void
    {
        $this->makeComponent('alert', '<div class="alert alert-{{ $type }}">{{ $slot }}</div>');

        $out = $this->render('<x-alert type="success" />');
        $this->assertStringContainsString('alert-success', $out);
    }

    public function test_self_closing_with_bound_variable(): void
    {
        $this->makeComponent('alert', '<div class="alert-{{ $type }}"></div>');

        $out = $this->render('<x-alert :type="$status" />', ['status' => 'danger']);
        $this->assertStringContainsString('alert-danger', $out);
    }

    public function test_self_closing_with_boolean_true_attribute(): void
    {
        $this->makeComponent('button', '<button {{ $disabled ? "disabled" : "" }}>Click</button>');

        $out = $this->render('<x-button :disabled="true" />');
        $this->assertStringContainsString('disabled', $out);
    }

    public function test_self_closing_with_boolean_false_omits_attribute(): void
    {
        $this->makeComponent('button', '<button {{ $disabled ? "disabled" : "" }}>Click</button>');

        $out = $this->render('<x-button :disabled="false" />');
        $this->assertStringNotContainsString('disabled', $out);
    }

    public function test_self_closing_multiple_attributes(): void
    {
        $this->makeComponent('input', '<input type="{{ $type }}" name="{{ $name }}" class="{{ $class }}" />');

        $out = $this->render('<x-input type="email" name="user_email" class="form-control" />');
        $this->assertStringContainsString('type="email"', $out);
        $this->assertStringContainsString('name="user_email"', $out);
        $this->assertStringContainsString('class="form-control"', $out);
    }

    // -----------------------------------------------------------------------
    // 2. DEFAULT SLOT
    // -----------------------------------------------------------------------

    public function test_default_slot_renders_content(): void
    {
        $this->makeComponent('card', '<div class="card">{{ $slot }}</div>');

        $out = $this->render('<x-card>Hello World</x-card>');
        $this->assertStringContainsString('Hello World', $out);
        $this->assertStringContainsString('class="card"', $out);
    }

    public function test_default_slot_with_html_content(): void
    {
        $this->makeComponent('card', '<div class="card">{!! $slot !!}</div>');

        $out = $this->render('<x-card><p>Rich <strong>content</strong></p></x-card>');
        $this->assertStringContainsString('<p>Rich <strong>content</strong></p>', $out);
    }

    public function test_default_slot_with_dynamic_content(): void
    {
        $this->makeComponent('wrapper', '<section>{!! $slot !!}</section>');

        $out = $this->render(
            '<x-wrapper>User: {{ $name }}</x-wrapper>',
            ['name' => 'Mirza']
        );
        $this->assertStringContainsString('User: Mirza', $out);
    }

    public function test_empty_default_slot(): void
    {
        $this->makeComponent('card', '<div class="card">{{ $slot }}</div>');

        $out = $this->render('<x-card></x-card>');
        $this->assertStringContainsString('class="card"', $out);
    }

    // -----------------------------------------------------------------------
    // 3. NAMED SLOTS
    // -----------------------------------------------------------------------

    public function test_named_slot_title_renders(): void
    {
        $this->makeComponent('card', '
            <div class="card">
                <div class="card-header">{!! $title !!}</div>
                <div class="card-body">{!! $slot !!}</div>
            </div>
        ');

        $out = $this->render('
            <x-card>
                <x-slot:title>My Title</x-slot:title>
                Body content
            </x-card>
        ');

        $this->assertStringContainsString('My Title', $out);
        $this->assertStringContainsString('Body content', $out);
        $this->assertStringContainsString('card-header', $out);
        $this->assertStringContainsString('card-body', $out);
    }

    public function test_multiple_named_slots(): void
    {
        $this->makeComponent('modal', '
            <div class="modal">
                <div class="modal-header">{!! $header !!}</div>
                <div class="modal-body">{!! $slot !!}</div>
                <div class="modal-footer">{!! $footer !!}</div>
            </div>
        ');

        $out = $this->render('
            <x-modal>
                <x-slot:header>Modal Title</x-slot:header>
                <x-slot:footer><button>Close</button></x-slot:footer>
                Main body content
            </x-modal>
        ');

        $this->assertStringContainsString('Modal Title', $out);
        $this->assertStringContainsString('Main body content', $out);
        $this->assertStringContainsString('Close', $out);
        $this->assertStringContainsString('modal-header', $out);
        $this->assertStringContainsString('modal-footer', $out);
    }

    public function test_named_slot_with_dynamic_content(): void
    {
        $this->makeComponent('panel', '
            <div class="panel">
                <h2>{!! $title !!}</h2>
                {!! $slot !!}
            </div>
        ');

        $out = $this->render('
            <x-panel>
                <x-slot:title>{{ $heading }}</x-slot:title>
                Content here
            </x-panel>
        ', ['heading' => 'Dynamic Heading']);

        $this->assertStringContainsString('Dynamic Heading', $out);
        $this->assertStringContainsString('Content here', $out);
    }

    public function test_named_slot_with_kebab_case_converted_to_camel(): void
    {
        $this->makeComponent('card', '
            <div>
                <div class="footer">{!! $cardFooter !!}</div>
                {!! $slot !!}
            </div>
        ');

        $out = $this->render('
            <x-card>
                <x-slot:card-footer>Footer Text</x-slot:card-footer>
                Body
            </x-card>
        ');

        $this->assertStringContainsString('Footer Text', $out);
    }

    // -----------------------------------------------------------------------
    // 4. ATTRIBUTES BAG
    // -----------------------------------------------------------------------

    public function test_attributes_bag_renders_all_extra_attributes(): void
    {
        $this->makeComponent('button', '<button {{ $attributes }}>{{ $slot }}</button>');

        $out = $this->render('<x-button class="btn btn-primary" id="save-btn">Save</x-button>');
        $this->assertStringContainsString('class="btn btn-primary"', $out);
        $this->assertStringContainsString('id="save-btn"', $out);
        $this->assertStringContainsString('Save', $out);
    }

    public function test_attributes_bag_merge_classes(): void
    {
        $this->makeComponent('button', '
            <button {{ $attributes->merge(["class" => "btn"]) }}>{{ $slot }}</button>
        ');

        $out = $this->render('<x-button class="btn-primary">Click</x-button>');
        $this->assertStringContainsString('btn', $out);
        $this->assertStringContainsString('btn-primary', $out);
    }

    public function test_attributes_bag_only(): void
    {
        $this->makeComponent('input', '
            <input {{ $attributes->only(["type", "name"]) }} />
        ');

        $out = $this->render('<x-input type="text" name="email" class="form-control" id="email-field" />');
        $this->assertStringContainsString('type="text"', $out);
        $this->assertStringContainsString('name="email"', $out);
        $this->assertStringNotContainsString('form-control', $out);
        $this->assertStringNotContainsString('email-field', $out);
    }

    public function test_attributes_bag_except(): void
    {
        $this->makeComponent('input', '
            <input {{ $attributes->except(["class"]) }} />
        ');

        $out = $this->render('<x-input type="text" name="email" class="form-control" />');
        $this->assertStringContainsString('type="text"', $out);
        $this->assertStringContainsString('name="email"', $out);
        $this->assertStringNotContainsString('form-control', $out);
    }

    public function test_attributes_bag_has(): void
    {
        $this->makeComponent('input', '
            <input {{ $attributes }} @if($attributes->has("required")) required @endif />
        ');

        $out = $this->render('<x-input type="text" required="true" />');
        $this->assertStringContainsString('required', $out);
    }

    public function test_attributes_boolean_true_renders_attribute_name_only(): void
    {
        $this->makeComponent('input', '<input {{ $attributes }} />');

        $out = $this->render('<x-input :disabled="true" />');
        $this->assertStringContainsString('disabled', $out);
    }

    public function test_attributes_boolean_false_omitted(): void
    {
        $this->makeComponent('input', '<input {{ $attributes }} />');

        $out = $this->render('<x-input :disabled="false" />');
        $this->assertStringNotContainsString('disabled', $out);
    }

    // -----------------------------------------------------------------------
    // 5. NESTED COMPONENTS
    // -----------------------------------------------------------------------

    public function test_component_nested_inside_component(): void
    {
        $this->makeComponent('alert', '<div class="alert alert-{{ $type }}">{{ $slot }}</div>');
        $this->makeComponent('card', '<div class="card">{!! $slot !!}</div>');

        $out = $this->render('
            <x-card>
                <x-alert type="warning">Watch out!</x-alert>
            </x-card>
        ');

        $this->assertStringContainsString('class="card"', $out);
        $this->assertStringContainsString('alert-warning', $out);
        $this->assertStringContainsString('Watch out!', $out);
    }

    public function test_three_levels_deep_nesting(): void
    {
        $this->makeComponent('layout', '<main>{!! $slot !!}</main>');
        $this->makeComponent('card',   '<div class="card">{!! $slot !!}</div>');
        $this->makeComponent('badge',  '<span class="badge badge-{{ $type }}">{{ $slot }}</span>');

        $out = $this->render('
            <x-layout>
                <x-card>
                    <x-badge type="success">Active</x-badge>
                </x-card>
            </x-layout>
        ');

        $this->assertStringContainsString('<main>', $out);
        $this->assertStringContainsString('class="card"', $out);
        $this->assertStringContainsString('badge-success', $out);
        $this->assertStringContainsString('Active', $out);
    }

    public function test_named_slots_dont_bleed_between_sibling_components(): void
    {
        $this->makeComponent('card', '
            <div class="card">
                <div class="header">{!! $title ?? "" !!}</div>
                {!! $slot !!}
            </div>
        ');

        $out = $this->render('
            <x-card>
                <x-slot:title>First Card</x-slot:title>
                First body
            </x-card>
            <x-card>
                <x-slot:title>Second Card</x-slot:title>
                Second body
            </x-card>
        ');

        $this->assertStringContainsString('First Card', $out);
        $this->assertStringContainsString('Second Card', $out);
        $this->assertStringContainsString('First body', $out);
        $this->assertStringContainsString('Second body', $out);
        // Each title should appear exactly once
        $this->assertSame(1, substr_count($out, 'First Card'));
        $this->assertSame(1, substr_count($out, 'Second Card'));
    }

    public function test_nested_component_with_named_slots(): void
    {
        $this->makeComponent('layout', '
            <html>
                <head>{!! $head ?? "" !!}</head>
                <body>{!! $slot !!}</body>
            </html>
        ');
        $this->makeComponent('card', '
            <div class="card">
                <div class="title">{!! $title ?? "" !!}</div>
                <div class="body">{!! $slot !!}</div>
                <div class="footer">{!! $footer ?? "" !!}</div>
            </div>
        ');

        $out = $this->render('
            <x-layout>
                <x-slot:head><title>My Page</title></x-slot:head>
                <x-card>
                    <x-slot:title>Card Title</x-slot:title>
                    <x-slot:footer>Card Footer</x-slot:footer>
                    Card Body
                </x-card>
            </x-layout>
        ');

        $this->assertStringContainsString('My Page', $out);
        $this->assertStringContainsString('Card Title', $out);
        $this->assertStringContainsString('Card Body', $out);
        $this->assertStringContainsString('Card Footer', $out);
    }

    // -----------------------------------------------------------------------
    // 6. COMPONENTS INSIDE LOOPS
    // -----------------------------------------------------------------------

    public function test_component_inside_foreach(): void
    {
        $this->makeComponent('badge', '<span class="badge">{{ $label }}</span>');

        $out = $this->render('
            @foreach($tags as $tag)
                <x-badge :label="$tag" />
            @endforeach
        ', ['tags' => ['PHP', 'Laravel', 'Nitro']]);

        $this->assertStringContainsString('PHP', $out);
        $this->assertStringContainsString('Laravel', $out);
        $this->assertStringContainsString('Nitro', $out);
        $this->assertSame(3, substr_count($out, 'class="badge"'));
    }

    public function test_component_inside_foreach_with_loop_index_as_bound_attribute(): void
    {
        $this->makeComponent('row', '<div data-index="{{ $index }}">{{ $slot }}</div>');

        $out = $this->render('
            @foreach($items as $item)
                <x-row :index="$loop->index">{{ $item }}</x-row>
            @endforeach
        ', ['items' => ['alpha', 'beta', 'gamma']]);

        $this->assertStringContainsString('data-index="0"', $out);
        $this->assertStringContainsString('data-index="1"', $out);
        $this->assertStringContainsString('data-index="2"', $out);
        $this->assertStringContainsString('alpha', $out);
        $this->assertStringContainsString('beta', $out);
        $this->assertStringContainsString('gamma', $out);
    }

    public function test_component_inside_foreach_with_conditional(): void
    {
        $this->makeComponent('user-row', '
        <div class="user {{ $active ? "active" : "inactive" }}">{{ $name }}</div>
    ');

        $out = $this->render('
        @foreach($users as $user)
            @php $name = $user["name"]; $active = $user["active"]; @endphp
            <x-user-row :name="$name" :active="$active" />
        @endforeach
    ', [
            'users' => [
                ['name' => 'Alice',   'active' => true],
                ['name' => 'Bob',     'active' => false],
                ['name' => 'Charlie', 'active' => true],
            ]
        ]);

        $this->assertStringContainsString('Alice', $out);
        $this->assertStringContainsString('Bob', $out);
        $this->assertStringContainsString('Charlie', $out);
        $this->assertStringContainsString('inactive', $out);
    }

    public function test_nested_component_inside_foreach_with_named_slot(): void
    {
        $this->makeComponent('card', '
            <div class="card">
                <div class="title">{!! $title ?? "" !!}</div>
                {!! $slot !!}
            </div>
        ');
        $this->makeComponent('badge', '<span class="badge">{{ $label }}</span>');

        $out = $this->render('
            @foreach($users as $user)
                <x-card>
                    <x-slot:title>{{ $user["name"] }}</x-slot:title>
                    @foreach($user["roles"] as $role)
                        <x-badge :label="$role" />
                    @endforeach
                </x-card>
            @endforeach
        ', [
            'users' => [
                ['name' => 'Alice', 'roles' => ['admin', 'editor']],
                ['name' => 'Bob',   'roles' => ['user']],
            ]
        ]);

        $this->assertStringContainsString('Alice', $out);
        $this->assertStringContainsString('Bob', $out);
        $this->assertStringContainsString('admin', $out);
        $this->assertStringContainsString('editor', $out);
        $this->assertStringContainsString('user', $out);
        $this->assertSame(2, substr_count($out, 'class="card"'));
        $this->assertSame(3, substr_count($out, 'class="badge"'));
    }

    // -----------------------------------------------------------------------
    // 7. COMPONENTS WITH CONDITIONALS INSIDE
    // -----------------------------------------------------------------------

    public function test_component_with_if_inside(): void
    {
        $this->makeComponent('alert', '
            <div class="alert alert-{{ $type }}">
                @if(isset($title))
                    <strong>{{ $title }}</strong>
                @endif
                {{ $slot }}
            </div>
        ');

        $out = $this->render('<x-alert type="info" title="Note">Some message</x-alert>');
        $this->assertStringContainsString('Note', $out);
        $this->assertStringContainsString('Some message', $out);
        $this->assertStringContainsString('<strong>', $out);
    }

    public function test_component_without_optional_attribute(): void
    {
        $this->makeComponent('alert', '
            <div class="alert alert-{{ $type }}">
                @if(isset($title))
                    <strong>{{ $title }}</strong>
                @endif
                {{ $slot }}
            </div>
        ');

        $out = $this->render('<x-alert type="info">No title here</x-alert>');
        $this->assertStringNotContainsString('<strong>', $out);
        $this->assertStringContainsString('No title here', $out);
    }

    public function test_component_with_foreach_inside(): void
    {
        $this->makeComponent('tag-list', '
            <ul>
                @foreach($tags as $tag)
                    <li>{{ $tag }}</li>
                @endforeach
            </ul>
        ');

        $out = $this->render('<x-tag-list :tags="$myTags" />', [
            'myTags' => ['PHP', 'MySQL', 'Redis']
        ]);

        $this->assertStringContainsString('<ul>', $out);
        $this->assertStringContainsString('<li>PHP</li>', $out);
        $this->assertStringContainsString('<li>MySQL</li>', $out);
        $this->assertStringContainsString('<li>Redis</li>', $out);
    }

    // -----------------------------------------------------------------------
    // 8. MOST COMPLEX — full dashboard-style template
    // -----------------------------------------------------------------------

    public function test_full_dashboard_complex_render(): void
    {
        // Components
        $this->makeComponent('layout', '
            <html>
                <head><title>{{ $pageTitle ?? "App" }}</title>{!! $head ?? "" !!}</head>
                <body>{!! $slot !!}</body>
            </html>
        ');

        $this->makeComponent('card', '
            <div class="card {{ $class ?? "" }}">
                @if(isset($title))
                    <div class="card-title">{!! $title !!}</div>
                @endif
                <div class="card-body">{!! $slot !!}</div>
                @if(isset($footer))
                    <div class="card-footer">{!! $footer !!}</div>
                @endif
            </div>
        ');

        $this->makeComponent('user-row', '
            <div class="user-row">
                <strong>{{ $name }}</strong>
                @if(isset($actions))
                    <div class="actions">{!! $actions !!}</div>
                @endif
                {!! $slot !!}
            </div>
        ');

        $this->makeComponent('button', '
            <button class="btn btn-{{ $variant ?? "default" }}" {{ $attributes->except(["variant"]) }}>
                {{ $slot }}
            </button>
        ');

        $this->makeComponent('badge', '
            <span class="badge badge-{{ $type ?? "secondary" }}">{{ $label }}</span>
        ');

        // The full template
        $out = $this->render('
            <x-layout page-title="Dashboard">
                <x-slot:head>
                    <meta charset="UTF-8">
                </x-slot:head>

                <x-card class="shadow-lg">
                    <x-slot:title>User Management</x-slot:title>
                    <x-slot:footer>
                        Total: {{ count($users) }} users
                    </x-slot:footer>

                    @foreach($users as $user)
                        <x-user-row :name="$user[\'name\']">
                            <x-slot:actions>
                                <x-button variant="primary" data-id="{{ $loop->index }}">Edit</x-button>
                                <x-button variant="danger">Delete</x-button>
                            </x-slot:actions>

                            @if(count($user[\'roles\']) > 0)
                                @foreach($user[\'roles\'] as $role)
                                    <x-badge :label="$role" type="info" />
                                @endforeach
                            @else
                                <x-badge label="No Roles" type="warning" />
                            @endif
                        </x-user-row>
                    @endforeach
                </x-card>
            </x-layout>
        ', [
            'users' => [
                ['name' => 'Alice',   'roles' => ['admin', 'editor']],
                ['name' => 'Bob',     'roles' => []],
                ['name' => 'Charlie', 'roles' => ['user']],
            ]
        ]);

        // Layout
        $this->assertStringContainsString('<html>', $out);
        $this->assertStringContainsString('UTF-8', $out);

        // Card structure
        $this->assertStringContainsString('shadow-lg', $out);
        $this->assertStringContainsString('User Management', $out);
        $this->assertStringContainsString('Total: 3 users', $out);

        // Users rendered
        $this->assertStringContainsString('Alice', $out);
        $this->assertStringContainsString('Bob', $out);
        $this->assertStringContainsString('Charlie', $out);

        // Buttons
        $this->assertStringContainsString('btn-primary', $out);
        $this->assertStringContainsString('btn-danger', $out);
        $this->assertStringContainsString('Edit', $out);
        $this->assertStringContainsString('Delete', $out);

        // Badges — roles
        $this->assertStringContainsString('admin', $out);
        $this->assertStringContainsString('editor', $out);
        $this->assertStringContainsString('badge-info', $out);

        // Bob has no roles
        $this->assertStringContainsString('No Roles', $out);
        $this->assertStringContainsString('badge-warning', $out);

        // Correct number of user rows
        $this->assertSame(3, substr_count($out, 'class="user-row"'));
    }

    // -----------------------------------------------------------------------
    // 9. ONCE DIRECTIVE
    // -----------------------------------------------------------------------

    public function test_once_renders_only_once(): void
    {
        $out = $this->render('
        @foreach($items as $item)
            {{ $item }}
            @once
                <script>loaded</script>
            @endonce
        @endforeach
    ', ['items' => ['a', 'b', 'c']]);

        $this->assertStringContainsString('a', $out);
        $this->assertStringContainsString('b', $out);
        $this->assertStringContainsString('c', $out);
        $this->assertSame(1, substr_count($out, '<script>loaded</script>'));
    }

    // -----------------------------------------------------------------------
    // @aware
    // -----------------------------------------------------------------------

    public function test_aware_pulls_from_parent_component(): void
    {
        $this->makeComponent('theme-provider', '{!! $slot !!}');
        $this->makeComponent('child-button', '
        @aware([\'color\'])
        <button class="btn-{{ $color }}">{{ $slot }}</button>
    ');

        $out = $this->render('
        <x-theme-provider color="blue">
            <x-child-button>Click</x-child-button>
        </x-theme-provider>
    ');

        $this->assertStringContainsString('btn-blue', $out);
        $this->assertStringContainsString('Click', $out);
    }

    public function test_aware_pulls_nearest_parent(): void
    {
        $this->makeComponent('outer', '{!! $slot !!}');
        $this->makeComponent('inner', '{!! $slot !!}');
        $this->makeComponent('leaf', '
        @aware([\'color\'])
        <span class="color-{{ $color }}">{{ $slot }}</span>
    ');

        $out = $this->render('
        <x-outer color="red">
            <x-inner color="green">
                <x-leaf>Text</x-leaf>
            </x-inner>
        </x-outer>
    ');

        // Should get nearest parent (inner) color
        $this->assertStringContainsString('color-green', $out);
    }

    public function test_aware_multiple_keys(): void
    {
        $this->makeComponent('form-group', '{!! $slot !!}');
        $this->makeComponent('form-input', '
        @aware([\'size\', \'theme\'])
        <input class="input-{{ $size }} theme-{{ $theme }}" />
    ');

        $out = $this->render('
        <x-form-group size="lg" theme="dark">
            <x-form-input />
        </x-form-group>
    ');

        $this->assertStringContainsString('input-lg', $out);
        $this->assertStringContainsString('theme-dark', $out);
    }

    // -----------------------------------------------------------------------
    // @teleport
    // -----------------------------------------------------------------------

    public function test_teleport_moves_content_to_target(): void
    {
        $out = $this->render('
        <div class="page">
            <p>Main content</p>
            @teleport("modals")
                <div class="modal">I was teleported</div>
            @endteleport
        </div>
        <div id="modals">
            @teleportTarget("modals")
        </div>
    ');

        $this->assertStringContainsString('Main content', $out);
        $this->assertStringContainsString('I was teleported', $out);

        // Teleported content should appear AFTER main content in output
        $this->assertGreaterThan(
            strpos($out, 'Main content'),
            strpos($out, 'I was teleported')
        );

        // Should NOT appear inline where @teleport was declared
        $pageSection  = substr($out, 0, strpos($out, '<div id="modals">'));
        $this->assertStringNotContainsString('I was teleported', $pageSection);
    }

    public function test_teleport_multiple_blocks_same_target(): void
    {
        $out = $this->render('
        @teleport("scripts")
            <script>one</script>
        @endteleport

        @teleport("scripts")
            <script>two</script>
        @endteleport

        <div id="scripts">
            @teleportTarget("scripts")
        </div>
    ');

        $this->assertStringContainsString('<script>one</script>', $out);
        $this->assertStringContainsString('<script>two</script>', $out);
        $this->assertSame(1, substr_count($out, '<div id="scripts">'));
    }

    public function test_teleport_from_inside_component(): void
    {
        $this->makeComponent('modal-trigger', '
        @teleport("modals")
            <div class="modal modal-{{ $name }}">Modal content</div>
        @endteleport
        <button>Open {{ $name }}</button>
    ');

        $out = $this->render('
        <x-modal-trigger name="confirm" />
        <x-modal-trigger name="delete" />

        <div id="modals">
            @teleportTarget("modals")
        </div>
    ');

        $this->assertStringContainsString('modal-confirm', $out);
        $this->assertStringContainsString('modal-delete', $out);
        $this->assertStringContainsString('Open confirm', $out);
        $this->assertStringContainsString('Open delete', $out);

        // Modals should be in the target div, not inline
        $targetPos  = strpos($out, '<div id="modals">');
        $confirmPos = strpos($out, 'modal-confirm');
        $this->assertGreaterThan($targetPos, $confirmPos);
    }

    public function test_teleport_different_targets(): void
    {
        $out = $this->render('
        @teleport("head")
            <link rel="stylesheet" href="style.css">
        @endteleport

        @teleport("scripts")
            <script src="app.js"></script>
        @endteleport

        <head>@teleportTarget("head")</head>
        <body>
            <div id="scripts">@teleportTarget("scripts")</div>
        </body>
    ');

        $this->assertStringContainsString('style.css', $out);
        $this->assertStringContainsString('app.js', $out);

        // Each in correct target
        $headPos   = strpos($out, '<head>');
        $scriptPos = strpos($out, '<div id="scripts">');
        $cssPos    = strpos($out, 'style.css');
        $jsPos     = strpos($out, 'app.js');

        $this->assertGreaterThan($headPos, $cssPos);
        $this->assertGreaterThan($scriptPos, $jsPos);
    }
}
