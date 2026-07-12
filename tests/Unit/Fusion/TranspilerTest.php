<?php

namespace Tests\Unit\Fusion;

use Nitro\Fusion\JsTranspile\JsTranspiler;
use PHPUnit\Framework\TestCase;

/**
 * Fusion's PHP→JS transpiler (vendored & adapted from Viewi, MIT) is the engine
 * that turns a Livewire-style component into client-side JS. These tests pin the
 * shapes Fusion relies on: props → class fields, methods → methods, PHP operators
 * → their JS equivalents, and control flow.
 */
class TranspilerTest extends TestCase
{
    private function js(string $php): string
    {
        // Fusion components are always namespaced (App\Components\*); the
        // transpiler requires a namespace, so inject one for these snippets.
        $php = str_replace('<?php', "<?php\nnamespace App\\Components;", $php);
        return (string) (new JsTranspiler())->convert($php);
    }

    public function test_public_prop_becomes_a_class_field(): void
    {
        $js = $this->js('<?php class Counter { public int $count = 0; }');
        $this->assertStringContainsString('class Counter', $js);
        $this->assertStringContainsString('count = 0;', $js);
    }

    public function test_method_body_transpiles_this_and_increment(): void
    {
        $js = $this->js('<?php class C { public int $n = 0; public function inc(): void { $this->n++; } }');
        $this->assertStringContainsString('inc()', $js);
        $this->assertStringContainsString('$this.n++', $js);
    }

    public function test_string_concat_becomes_plus(): void
    {
        $js = $this->js('<?php class C { public string $name = ""; public function label() { return "Hi " . $this->name; } }');
        $this->assertStringContainsString('"Hi " + $this.name', $js);
    }

    public function test_if_else_control_flow(): void
    {
        $js = $this->js('<?php class C { public int $n = 0; public function f() { if ($this->n > 0) { return "pos"; } else { return "neg"; } } }');
        $this->assertStringContainsString('if ($this.n > 0)', $js);
        $this->assertStringContainsString('else', $js);
    }

    public function test_foreach_loop(): void
    {
        $js = $this->js('<?php class C { public array $items = []; public function total() { $t = 0; foreach ($this->items as $i) { $t = $t + $i; } return $t; } }');
        $this->assertStringContainsString('for', $js);          // foreach → for/of/in
        $this->assertStringContainsString('$this.items', $js);
    }

    public function test_method_call_and_arithmetic(): void
    {
        $js = $this->js('<?php class C { public int $n = 2; public function double() { return $this->n * 2; } public function quad() { return $this->double() * 2; } }');
        $this->assertStringContainsString('$this.n * 2', $js);
        $this->assertStringContainsString('$this.double()', $js);
    }

    public function test_function_registry_loads(): void
    {
        $registry = require __DIR__ . '/../../../src/Fusion/JsTranspile/functions.php';
        $this->assertIsArray($registry);
        $this->assertGreaterThan(300, count($registry));
        $this->assertArrayHasKey('count', $registry);
        $this->assertArrayHasKey('str_replace', $registry);
        // reserved-word constructs got underscore-prefixed, valid + resolvable
        $this->assertTrue(class_exists($registry['echo']));
        $this->assertTrue(class_exists($registry['empty']));
    }
}
