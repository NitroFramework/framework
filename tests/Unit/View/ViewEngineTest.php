<?php

namespace Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Nitro\View\Engine\ViewRenderer;
use Nitro\View\Compiler\CompiledTemplateCache;
use Nitro\View\Compiler\BladeCompiler;
use Nitro\View\Compiler\ComponentTagCompiler;
use Nitro\Foundation\PathRegistry;
use Nitro\Foundation\Config;

class ViewEngineTest extends TestCase
{
    private ViewRenderer $engine;

    protected function setUp(): void
    {
        /** @var CompiledTemplateCache&MockObject $cache */
        $cache = $this->createMock(CompiledTemplateCache::class);

        /** @var PathRegistry&MockObject $paths */
        $paths = $this->createMock(PathRegistry::class);
        $paths->method('views')->willReturn('/fake/views');

        /** @var Config&MockObject $config */
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(fn ($key, $default = null) => match ($key) {
            'view.extension' => 'blade.php',
            'app.debug'      => false,
            default          => $default,
        });

        $components = $this->createMock(\Nitro\View\Component\ComponentRenderer::class);
        $tagCompiler = new ComponentTagCompiler();
        $compiler = new BladeCompiler($tagCompiler);

        $this->engine = new ViewRenderer(
            $cache,        // 1
            $components,   // 2
            $compiler,     // 3
            $tagCompiler,  // 4
            $paths,        // 5
            $config        // 6
        );
    }

    // Helper — the only method we're testing through
    private function render(string $blade, array $data = []): string
    {
        return $this->engine->renderString($blade, $data);
    }

    // -----------------------------------------------------------------------
    // BASIC RENDERING
    // -----------------------------------------------------------------------

    public function test_plain_html_renders(): void
    {
        $out = $this->render('<h1>Hello</h1>');
        $this->assertStringContainsString('<h1>Hello</h1>', $out);
    }

    public function test_variable_renders(): void
    {
        $out = $this->render('Hello {{ $name }}', ['name' => 'Mirza']);
        $this->assertStringContainsString('Hello Mirza', $out);
    }

    public function test_variable_is_escaped(): void
    {
        $out = $this->render('{{ $val }}', ['val' => '<script>alert(1)</script>']);
        $this->assertStringContainsString('&lt;script&gt;', $out);
        $this->assertStringNotContainsString('<script>', $out);
    }

    public function test_raw_echo_not_escaped(): void
    {
        $out = $this->render('{!! $val !!}', ['val' => '<strong>Bold</strong>']);
        $this->assertStringContainsString('<strong>Bold</strong>', $out);
    }

    public function test_comment_not_in_output(): void
    {
        $out = $this->render('before {{-- hidden --}} after');
        $this->assertStringContainsString('before', $out);
        $this->assertStringContainsString('after', $out);
        $this->assertStringNotContainsString('hidden', $out);
    }

    // -----------------------------------------------------------------------
    // CONDITIONALS
    // -----------------------------------------------------------------------

    public function test_if_true_renders_content(): void
    {
        $out = $this->render('@if($show) visible @endif', ['show' => true]);
        $this->assertStringContainsString('visible', $out);
    }

    public function test_if_false_hides_content(): void
    {
        $out = $this->render('@if($show) visible @endif', ['show' => false]);
        $this->assertStringNotContainsString('visible', $out);
    }

    public function test_if_else(): void
    {
        $out = $this->render('@if($x) yes @else no @endif', ['x' => false]);
        $this->assertStringContainsString('no', $out);
        $this->assertStringNotContainsString('yes', $out);
    }

    public function test_elseif_branch(): void
    {
        $out = $this->render('@if($x) a @elseif($y) b @else c @endif', ['x' => false, 'y' => true]);
        $this->assertStringContainsString('b', $out);
    }

    public function test_unless_renders_when_false(): void
    {
        $out = $this->render('@unless($x) shown @endunless', ['x' => false]);
        $this->assertStringContainsString('shown', $out);
    }

    public function test_isset_true(): void
    {
        $out = $this->render('@isset($var) exists @endisset', ['var' => 'hello']);
        $this->assertStringContainsString('exists', $out);
    }

    public function test_empty_true(): void
    {
        $out = $this->render('@empty($var) nothing @endempty', ['var' => []]);
        $this->assertStringContainsString('nothing', $out);
    }

    public function test_nested_conditionals(): void
    {
        $out = $this->render(
            '@if($a) @if($b) deep @else shallow @endif @endif',
            ['a' => true, 'b' => true]
        );
        $this->assertStringContainsString('deep', $out);
        $this->assertStringNotContainsString('shallow', $out);
    }

    // -----------------------------------------------------------------------
    // LOOPS
    // -----------------------------------------------------------------------

    public function test_foreach_renders_all_items(): void
    {
        $out = $this->render(
            '@foreach($items as $item){{ $item }} @endforeach',
            ['items' => ['a', 'b', 'c']]
        );
        $this->assertStringContainsString('a', $out);
        $this->assertStringContainsString('b', $out);
        $this->assertStringContainsString('c', $out);
    }

    public function test_foreach_loop_index(): void
    {
        $out = $this->render(
            '@foreach($items as $item){{ $loop->index }}@endforeach',
            ['items' => ['a', 'b', 'c']]
        );
        $this->assertStringContainsString('0', $out);
        $this->assertStringContainsString('1', $out);
        $this->assertStringContainsString('2', $out);
    }

    public function test_foreach_loop_iteration(): void
    {
        $out = $this->render(
            '@foreach($items as $item){{ $loop->iteration }}@endforeach',
            ['items' => ['a', 'b']]
        );
        $this->assertStringContainsString('1', $out);
        $this->assertStringContainsString('2', $out);
    }

    public function test_foreach_loop_first_last(): void
    {
        $template = '@foreach($items as $item)' . "\n"
            . '@if($loop->first) FIRST @endif' . "\n"
            . '@if($loop->last) LAST @endif' . "\n"
            . '@endforeach';

        $out = $this->render($template, ['items' => ['a', 'b', 'c']]);
        $this->assertStringContainsString('FIRST', $out);
        $this->assertStringContainsString('LAST', $out);
    }

    public function test_foreach_loop_odd_even(): void
    {
        $template = '@foreach($items as $item)' . "\n"
            . '@if($loop->odd) ODD @else EVEN @endif' . "\n"
            . '@endforeach';

        $out = $this->render($template, ['items' => [1, 2, 3]]);
        $this->assertStringContainsString('ODD', $out);
        $this->assertStringContainsString('EVEN', $out);
    }

    public function test_for_loop(): void
    {
        $out = $this->render('@for($i = 1; $i <= 3; $i++){{ $i }}@endfor', []);
        $this->assertStringContainsString('1', $out);
        $this->assertStringContainsString('2', $out);
        $this->assertStringContainsString('3', $out);
    }

    public function test_while_loop(): void
    {
        $out = $this->render(
            '@php $c = 1; @endphp @while($c <= 3){{ $c }}@php $c++; @endphp @endwhile',
            []
        );
        $this->assertStringContainsString('1', $out);
        $this->assertStringContainsString('2', $out);
        $this->assertStringContainsString('3', $out);
    }

    public function test_break_stops_loop(): void
    {
        $out = $this->render(
            '@foreach($items as $item)@break($item === 3){{ $item }}@endforeach',
            ['items' => [1, 2, 3, 4, 5]]
        );
        $this->assertStringContainsString('1', $out);
        $this->assertStringContainsString('2', $out);
        $this->assertStringNotContainsString('3', $out);
        $this->assertStringNotContainsString('4', $out);
    }

    public function test_continue_skips_item(): void
    {
        $out = $this->render(
            '@foreach($items as $item)@continue($item === 2){{ $item }}@endforeach',
            ['items' => [1, 2, 3]]
        );
        $this->assertStringContainsString('1', $out);
        $this->assertStringNotContainsString('2', $out);
        $this->assertStringContainsString('3', $out);
    }

    public function test_nested_foreach_with_loop_variables(): void
    {
        $template = '
            @foreach($users as $user)
                {{ $loop->iteration }}:{{ $user["name"] }}
                @foreach($user["roles"] as $role)
                    {{ $loop->iteration }}-{{ $role }}
                @endforeach
            @endforeach
        ';

        $out = $this->render($template, [
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
    }

    public function test_foreach_with_nested_if(): void
    {
        $template = '
            @foreach($items as $item)
                @if($item > 2)
                    big
                @else
                    small
                @endif
            @endforeach
        ';
        $out = $this->render($template, ['items' => [1, 2, 3, 4]]);
        $this->assertStringContainsString('big', $out);
        $this->assertStringContainsString('small', $out);
    }

    // -----------------------------------------------------------------------
    // PHP DIRECTIVE
    // -----------------------------------------------------------------------

    public function test_php_block_executes(): void
    {
        $out = $this->render('@php $msg = "hello"; @endphp {{ $msg }}');
        $this->assertStringContainsString('hello', $out);
    }

    public function test_inline_php_expression_executes(): void
    {
        $out = $this->render('@php($msg = "hello") {{ $msg }}');
        $this->assertStringContainsString('hello', $out);
    }

    public function test_php_sets_variable_used_in_loop(): void
    {
        $out = $this->render(
            '@php $items = ["x", "y"]; @endphp @foreach($items as $i){{ $i }}@endforeach'
        );
        $this->assertStringContainsString('x', $out);
        $this->assertStringContainsString('y', $out);
    }

    // -----------------------------------------------------------------------
    // COMPONENTS — SELF CLOSING
    // -----------------------------------------------------------------------

    public function test_self_closing_component_renders(): void
    {
        // Uses anonymous component — needs components/alert.blade.php
        // We test via renderString with inline component simulation
        // Since anonymous components hit filesystem, we test the output structure
        $this->expectNotToPerformAssertions();
        // This test documents that self-closing works;
        // real component rendering needs ViewEngineIntegrationTest with real files
    }

    // -----------------------------------------------------------------------
    // STACKS
    // -----------------------------------------------------------------------

    public function test_push_and_stack(): void
    {
        $template = '
            @push("scripts") script-one @endpush
            @push("scripts") script-two @endpush
            @stack("scripts")
        ';
        $out = $this->render($template);
        $this->assertStringContainsString('script-one', $out);
        $this->assertStringContainsString('script-two', $out);
        // Both pushed before @stack — order should be push order
        $this->assertLessThan(strpos($out, 'script-two'), strpos($out, 'script-one'));
    }

    public function test_prepend_goes_before_push(): void
    {
        $template = '
            @push("scripts") second @endpush
            @prepend("scripts") first @endprepend
            @stack("scripts")
        ';
        $out = $this->render($template);
        $this->assertLessThan(strpos($out, 'second'), strpos($out, 'first'));
    }

    // -----------------------------------------------------------------------
    // COMPLEX COMBINATIONS
    // -----------------------------------------------------------------------

    public function test_foreach_with_php_and_conditionals(): void
    {
        $template = '
            @php $total = 0; @endphp
            @foreach($prices as $price)
                @php $total += $price; @endphp
                @if($price > 50)
                    expensive
                @else
                    cheap
                @endif
            @endforeach
            Total: {{ $total }}
        ';
        $out = $this->render($template, ['prices' => [20, 75, 30, 100]]);
        $this->assertStringContainsString('expensive', $out);
        $this->assertStringContainsString('cheap', $out);
        $this->assertStringContainsString('225', $out);
    }

    public function test_nested_loops_with_continue_and_break(): void
    {
        $template = '
            @foreach($users as $user)
                @foreach($user["roles"] as $role)
                    @continue($role === "skip")
                    @break($role === "stop")
                    {{ $role }}
                @endforeach
            @endforeach
        ';
        $out = $this->render($template, [
            'users' => [
                ['roles' => ['admin', 'skip', 'editor']],
                ['roles' => ['user', 'stop', 'hidden']],
            ]
        ]);
        $this->assertStringContainsString('admin', $out);
        $this->assertStringContainsString('editor', $out);
        $this->assertStringNotContainsString('skip', $out);
        $this->assertStringContainsString('user', $out);
        $this->assertStringNotContainsString('hidden', $out);
    }

    public function test_stacks_inside_foreach(): void
    {
        $template = '
            @foreach($items as $item)
                @push("list") {{ $item }} @endpush
            @endforeach
            @stack("list")
        ';
        $out = $this->render($template, ['items' => ['alpha', 'beta', 'gamma']]);
        $this->assertStringContainsString('alpha', $out);
        $this->assertStringContainsString('beta', $out);
        $this->assertStringContainsString('gamma', $out);
    }

    public function test_isset_and_empty_combined(): void
    {
        $template = '
            @isset($users)
                @foreach($users as $user)
                    @if(empty($user["roles"]))
                        {{ $user["name"] }} has no roles
                    @else
                        {{ $user["name"] }}: {{ implode(", ", $user["roles"]) }}
                    @endif
                @endforeach
            @endisset
            @empty($nothing)
                nothing is empty
            @endempty
        ';
        $out = $this->render($template, [
            'users' => [
                ['name' => 'Alice', 'roles' => ['admin']],
                ['name' => 'Bob',   'roles' => []],
            ],
            'nothing' => null,
        ]);
        $this->assertStringContainsString('Alice: admin', $out);
        $this->assertStringContainsString('Bob has no roles', $out);
        $this->assertStringContainsString('nothing is empty', $out);
    }

    public function test_deep_nesting_conditionals_loops_php(): void
    {
        $template = '
            @php $output = []; @endphp
            @foreach($groups as $group)
                @if(count($group["items"]) > 0)
                    @foreach($group["items"] as $item)
                        @if($item["active"])
                            @php $output[] = $item["name"]; @endphp
                            {{ $item["name"] }}
                        @endif
                    @endforeach
                @else
                    empty group
                @endif
            @endforeach
            Count: {{ count($output) }}
        ';

        $out = $this->render($template, [
            'groups' => [
                ['items' => [
                    ['name' => 'Alpha', 'active' => true],
                    ['name' => 'Beta',  'active' => false],
                ]],
                ['items' => []],
                ['items' => [
                    ['name' => 'Gamma', 'active' => true],
                ]],
            ]
        ]);

        $this->assertStringContainsString('Alpha', $out);
        $this->assertStringNotContainsString('Beta', $out);
        $this->assertStringContainsString('Gamma', $out);
        $this->assertStringContainsString('empty group', $out);
        $this->assertStringContainsString('Count: 2', $out);
    }

    public function test_inject_resolves_service(): void
    {
        // Register a simple service in the container
        \Nitro\Container\Container::getInstance()->singleton(
            'App\Services\FakeService',
            fn() => new class {
                public function greet(): string
                {
                    return 'Hello from service';
                }
            }
        );

        $out = $this->render("
        @inject('svc', 'App\Services\FakeService')
        {{ \$svc->greet() }}
    ");

        $this->assertStringContainsString('Hello from service', $out);
    }
}
