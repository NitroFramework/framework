<?php

namespace Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use Nitro\View\Compiler\BladeCompiler;
use Nitro\View\Compiler\ComponentTagCompiler;

class CompilerTest extends TestCase
{
    private BladeCompiler $blade;
    private ComponentTagCompiler $tags;

    protected function setUp(): void
    {
        $this->tags  = new ComponentTagCompiler();
        $this->blade = new BladeCompiler($this->tags);
    }

    // Helper: run both compilers in the correct order
    private function compile(string $template): string
    {
        $compiled = $this->tags->compile($template);
        return $this->blade->compile($compiled);
    }

    // -----------------------------------------------------------------------
    // ECHO
    // -----------------------------------------------------------------------

    public function test_escaped_echo(): void
    {
        // Escaped echoes compile to the free function \nitro_e() rather
        // than $this->e() — global functions skip the vtable lookup and
        // get specialized by opcache more aggressively.
        $out = $this->compile('{{ $name }}');
        $this->assertStringContainsString('nitro_e(', $out);
        $this->assertStringContainsString('$name', $out);
    }

    public function test_raw_echo(): void
    {
        $out = $this->compile('{!! $html !!}');
        $this->assertStringNotContainsString('htmlspecialchars', $out);
        $this->assertStringContainsString('$html', $out);
    }

    public function test_echo_with_expression(): void
    {
        $out = $this->compile('{{ strtoupper($name) }}');
        $this->assertStringContainsString('strtoupper($name)', $out);
    }

    public function test_comment_stripped(): void
    {
        $out = $this->compile('{{-- this is a comment --}}');
        $this->assertStringNotContainsString('this is a comment', $out);
    }

    public function test_multiple_echos_on_one_line(): void
    {
        $out = $this->compile('{{ $first }} {{ $last }}');
        $this->assertStringContainsString('$first', $out);
        $this->assertStringContainsString('$last', $out);
    }

    // -----------------------------------------------------------------------
    // CONDITIONALS
    // -----------------------------------------------------------------------

    public function test_if_endif(): void
    {
        $out = $this->compile('@if($x) yes @endif');
        $this->assertStringContainsString('if($x):', $out);
        $this->assertStringContainsString('endif;', $out);
    }

    public function test_if_else_endif(): void
    {
        $out = $this->compile('@if($x) yes @else no @endif');
        $this->assertStringContainsString('if($x):', $out);
        $this->assertStringContainsString('else:', $out);
        $this->assertStringContainsString('endif;', $out);
    }

    public function test_if_elseif_else_endif(): void
    {
        $out = $this->compile('@if($x) a @elseif($y) b @else c @endif');
        $this->assertStringContainsString('elseif($y):', $out);
    }

    public function test_unless(): void
    {
        $out = $this->compile('@unless($x) hidden @endunless');
        $this->assertStringContainsString('if(!($x)):', $out);
        $this->assertStringContainsString('endif;', $out);
    }

    public function test_isset(): void
    {
        $out = $this->compile('@isset($var) exists @endisset');
        $this->assertStringContainsString('isset($var)', $out);
        $this->assertStringContainsString('endif;', $out);
    }

    public function test_empty(): void
    {
        $out = $this->compile('@empty($var) nothing @endempty');
        $this->assertStringContainsString('empty($var)', $out);
        $this->assertStringContainsString('endif;', $out);
    }

    public function test_nested_if_inside_if(): void
    {
        $out = $this->compile('@if($a) @if($b) deep @endif @endif');
        $this->assertStringContainsString('if($a):', $out);
        $this->assertStringContainsString('if($b):', $out);
        $this->assertSame(2, substr_count($out, 'endif;'));
    }

    // -----------------------------------------------------------------------
    // LOOPS
    // -----------------------------------------------------------------------

    public function test_foreach_endforeach(): void
    {
        $out = $this->compile('@foreach($items as $item) x @endforeach');
        $this->assertStringContainsString('foreach($__currentLoopData as $item)', $out);
        $this->assertStringContainsString('endforeach;', $out);
    }

    public function test_foreach_loop_variable_injected(): void
    {
        $out = $this->compile('@foreach($items as $item) x @endforeach');
        // Your compiler should inject $loop variable setup
        $this->assertStringContainsString('$loop', $out);
    }

    public function test_for_endfor(): void
    {
        $out = $this->compile('@for($i = 0; $i < 10; $i++) x @endfor');
        $this->assertStringContainsString('for($i = 0; $i < 10; $i++)', $out);
        $this->assertStringContainsString('endfor;', $out);
    }

    public function test_while_endwhile(): void
    {
        $out = $this->compile('@while($x > 0) x @endwhile');
        $this->assertStringContainsString('while($x > 0)', $out);
        $this->assertStringContainsString('endwhile;', $out);
    }

    public function test_forelse_empty(): void
    {
        $out = $this->compile('@forelse($items as $item) x @empty no items @endforelse');
        $this->assertStringContainsString('foreach', $out);
        $this->assertStringContainsString('empty', $out);
    }

    public function test_break_with_condition(): void
    {
        $out = $this->compile('@foreach($items as $item) @break($item > 5) @endforeach');
        $this->assertStringContainsString('break', $out);
    }

    public function test_continue_with_condition(): void
    {
        $out = $this->compile('@foreach($items as $item) @continue($item === 0) @endforeach');
        $this->assertStringContainsString('continue', $out);
    }

    public function test_nested_foreach(): void
    {
        $out = $this->compile('@foreach($users as $user) @foreach($user["roles"] as $role) x @endforeach @endforeach');
        $this->assertSame(2, substr_count($out, 'endforeach;'));
        // Don't count foreach literally, count endforeach instead
    }

    // -----------------------------------------------------------------------
    // SECTIONS / LAYOUT
    // -----------------------------------------------------------------------

    public function test_extends_compiled(): void
    {
        $out = $this->compile("@extends('layouts.app')");
        $this->assertStringContainsString('setParentView', $out);
        $this->assertStringContainsString('layouts.app', $out);
    }

    public function test_section_endsection(): void
    {
        $out = $this->compile("@section('title') Hello @endsection");
        $this->assertStringContainsString('startSection', $out);
        $this->assertStringContainsString('endSection', $out);
        $this->assertStringContainsString('title', $out);
    }

    public function test_yield_compiled(): void
    {
        $out = $this->compile("@yield('content')");
        $this->assertStringContainsString('getSection', $out);
        $this->assertStringContainsString('content', $out);
    }

    public function test_parent_compiled(): void
    {
        $out = $this->compile("@section('title') @parent Extra @endsection");
        $this->assertStringContainsString('getParentContent', $out);
    }

    public function test_append_compiled(): void
    {
        $out = $this->compile("@section('scripts') <script></script> @append");
        $this->assertStringContainsString('appendSection', $out);
    }

    // -----------------------------------------------------------------------
    // STACKS
    // -----------------------------------------------------------------------

    public function test_push_endpush(): void
    {
        $out = $this->compile("@push('scripts') <script></script> @endpush");
        $this->assertStringContainsString('startPush', $out);
        $this->assertStringContainsString('endPush', $out);
        $this->assertStringContainsString('scripts', $out);
    }

    public function test_stack_compiled(): void
    {
        $out = $this->compile("@stack('scripts')");
        $this->assertStringContainsString('yieldStack', $out);
        $this->assertStringContainsString('scripts', $out);
    }

    public function test_prepend_endprepend(): void
    {
        $out = $this->compile("@prepend('scripts') first @endprepend");
        $this->assertStringContainsString('startPrepend', $out);
        $this->assertStringContainsString('endPrepend', $out);
    }

    // -----------------------------------------------------------------------
    // PHP DIRECTIVE
    // -----------------------------------------------------------------------

    public function test_php_endphp(): void
    {
        $out = $this->compile('@php $x = 1; @endphp');
        $this->assertStringContainsString('<?php', $out);
        $this->assertStringContainsString('$x = 1;', $out);
    }

    public function test_inline_php_expression_compiles_to_complete_php_block(): void
    {
        $out = $this->compile('@php($x = 1)');
        $this->assertSame('<?php $x = 1; ?>', $out);
    }

    // -----------------------------------------------------------------------
    // MISC DIRECTIVES
    // -----------------------------------------------------------------------

    public function test_json_compiled(): void
    {
        $out = $this->compile('@json($data)');
        $this->assertStringContainsString('json_encode', $out);
    }

    public function test_csrf_compiled(): void
    {
        $out = $this->compile('@csrf');
        $this->assertStringContainsString('csrf', $out);
    }

    public function test_checked_compiled(): void
    {
        $out = $this->compile('@checked($value)');
        $this->assertStringContainsString('checked', $out);
    }

    public function test_selected_compiled(): void
    {
        $out = $this->compile('@selected($value)');
        $this->assertStringContainsString('selected', $out);
    }

    public function test_disabled_compiled(): void
    {
        $out = $this->compile('@disabled($value)');
        $this->assertStringContainsString('disabled', $out);
    }

    public function test_class_compiled(): void
    {
        $out = $this->compile("@class(['active' => true, 'hidden' => false])");
        $this->assertStringContainsString('class', $out);
    }

    // -----------------------------------------------------------------------
    // VERBATIM
    // -----------------------------------------------------------------------

    public function test_verbatim_preserved(): void
    {
        $out = $this->compile('@verbatim {{ $notCompiled }} @endverbatim');
        $this->assertStringContainsString('{{ $notCompiled }}', $out);
        $this->assertStringNotContainsString('htmlspecialchars', $out);
    }

    // -----------------------------------------------------------------------
    // COMPONENT TAG COMPILER
    // -----------------------------------------------------------------------

    public function test_self_closing_component(): void
    {
        $out = $this->compile('<x-alert />');
        $this->assertStringContainsString('renderComponent', $out);
        $this->assertStringContainsString("'alert'", $out);
    }

    public function test_self_closing_with_plain_attribute(): void
    {
        $out = $this->compile('<x-alert type="success" />');
        $this->assertStringContainsString("'type' => 'success'", $out);
    }

    public function test_self_closing_with_bound_attribute(): void
    {
        $out = $this->compile('<x-alert :type="$status" />');
        $this->assertStringContainsString("'type' => \$status", $out); // escaped dollar
    }

    public function test_opening_closing_component(): void
    {
        $out = $this->compile('<x-card> content </x-card>');
        $this->assertStringContainsString('startComponent', $out);
        $this->assertStringContainsString('endComponent', $out);
        $this->assertStringContainsString("'card'", $out);
    }

    public function test_component_with_multiple_attributes(): void
    {
        $out = $this->compile('<x-card class="shadow" :type="$status" size="lg" />');
        $this->assertStringContainsString("'class' => 'shadow'", $out);
        $this->assertStringContainsString("'type' => \$status", $out);
        $this->assertStringContainsString("'size' => 'lg'", $out);
    }

    public function test_named_slot_compiled(): void
    {
        $out = $this->compile('<x-slot:title> Hello </x-slot:title>');
        $this->assertStringContainsString('startNamedSlot', $out);
        $this->assertStringContainsString('endNamedSlot', $out);
        $this->assertStringContainsString("'title'", $out);
    }

    public function test_named_slot_with_dashes_camelcased(): void
    {
        $out = $this->compile('<x-slot:footer-content> Hello </x-slot:footer-content>');
        $this->assertStringContainsString('footerContent', $out);
    }

    public function test_nested_components_compiled(): void
    {
        $template = '<x-card><x-alert type="info" /></x-card>';
        $out      = $this->compile($template);
        $this->assertStringContainsString('startComponent', $out);
        $this->assertStringContainsString('renderComponent', $out);
        $this->assertStringContainsString('endComponent', $out);
    }

    public function test_component_with_named_slot_compiled(): void
    {
        $template = '<x-card><x-slot:title>My Title</x-slot:title>Body</x-card>';
        $out      = $this->compile($template);
        $this->assertStringContainsString('startComponent', $out);
        $this->assertStringContainsString('startNamedSlot', $out);
        $this->assertStringContainsString('endNamedSlot', $out);
        $this->assertStringContainsString('endComponent', $out);
    }

    public function test_deeply_nested_components_compiled(): void
    {
        $template = '
            <x-layout>
                <x-slot:content>
                    <x-card>
                        <x-slot:title>Title</x-slot:title>
                        <x-user-row>
                            <x-slot:actions>
                                <x-button action="edit" />
                            </x-slot:actions>
                        </x-user-row>
                    </x-card>
                </x-slot:content>
            </x-layout>
        ';
        $out = $this->compile($template);
        $this->assertStringContainsString('startComponent', $out);
        $this->assertStringContainsString('endComponent', $out);
        $this->assertStringContainsString('renderComponent', $out);
        // No raw x- tags should remain
        $this->assertStringNotContainsString('<x-', $out);
    }

    public function test_component_inside_foreach_compiled(): void
    {
        $template = '@foreach($items as $item)<x-card :title="$item" /></x-foreach>';
        $out      = $this->compile('@foreach($items as $item)<x-card :title="$item" />@endforeach');
        $this->assertStringContainsString('foreach', $out);
        $this->assertStringContainsString('renderComponent', $out);
        $this->assertStringContainsString("'title' => \$item", $out);
    }

    public function test_balanced_tags_assertion(): void
    {
        // Unbalanced — missing closing tag
        $this->expectException(\RuntimeException::class);
        $this->tags->compile('<x-card><x-alert>');
    }

    public function test_no_x_tags_remain_after_compile(): void
    {
        $template = '
            <x-layout>
                <x-slot:content>
                    <x-card type="primary">
                        <x-slot:footer>
                            <x-button label="Save" />
                        </x-slot:footer>
                    </x-card>
                </x-slot:content>
            </x-layout>
        ';
        $out = $this->compile($template);
        $this->assertStringNotContainsString('<x-', $out);
        $this->assertStringNotContainsString('</x-', $out);
    }
}
