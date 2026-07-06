<?php

namespace Tests\Unit\Debug;

use Nitro\Debug\Dumper;
use PHPUnit\Framework\TestCase;

class DumperTest extends TestCase
{
    protected function capture(mixed $value): string
    {
        ob_start();
        (new Dumper())->dump($value);
        return ob_get_clean();
    }

    public function test_emits_no_inline_onclick_handlers(): void
    {
        $output = $this->capture(['a' => 1, 'b' => 2]);
        $this->assertStringNotContainsString('onclick=', $output);
        $this->assertStringContainsString('data-nd-toggle', $output);
    }

    public function test_basic_scalars_render(): void
    {
        $this->assertStringContainsString('null', $this->capture(null));
        $this->assertStringContainsString('true', $this->capture(true));
        $this->assertStringContainsString('42', $this->capture(42));
        $this->assertStringContainsString('"hello"', $this->capture('hello'));
    }

    public function test_array_render_includes_count(): void
    {
        $out = $this->capture([1, 2, 3]);
        $this->assertStringContainsString('array(3)', $out);
    }

    public function test_string_with_html_is_escaped(): void
    {
        $out = $this->capture('<script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>alert', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    public function test_object_renders_class_name(): void
    {
        $out = $this->capture(new \stdClass());
        $this->assertStringContainsString('stdClass', $out);
    }
}
