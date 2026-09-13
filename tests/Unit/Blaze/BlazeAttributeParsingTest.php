<?php

namespace Tests\Unit\Blaze;

use Nitro\Blaze\BlazeCompiler;
use Nitro\Blaze\BlazeManager;
use PHPUnit\Framework\TestCase;

/**
 * A component attribute may contain a '>' inside its value.
 *
 * :title="$course->title" is the most ordinary thing in a Blade template, and
 * the attribute run used to stop at the first '>' — truncating the expression
 * to :title="$course- and compiling it to ['title' => ,]. The failure surfaced
 * as a PHP parse error in a hashed file under storage/cache/views, naming a
 * line number in generated code rather than the tag that caused it.
 */
class BlazeAttributeParsingTest extends TestCase
{
    private function compile(string $template): string
    {
        // A manager that says yes to everything: this is about the tag parser,
        // not about which components Blaze has decided it can optimise.
        $manager = new class(true, sys_get_temp_dir(), sys_get_temp_dir()) extends BlazeManager {
            public function isEnabled(string $name): bool
            {
                return true;
            }
        };

        return (new BlazeCompiler($manager))->compile($template);
    }

    public function test_a_bound_attribute_may_contain_an_arrow(): void
    {
        $out = $this->compile('<x-card :title="$course->title" />');

        $this->assertStringContainsString("'title' => \$course->title", $out);
        $this->assertStringNotContainsString("'title' => ,", $out);
    }

    public function test_a_paired_tag_may_contain_an_arrow(): void
    {
        $out = $this->compile('<x-layout :title="$course->title">body</x-layout>');

        $this->assertStringContainsString("'title' => \$course->title", $out);
        $this->assertStringContainsString('body', $out);
    }

    public function test_several_attributes_with_arrows(): void
    {
        $out = $this->compile('<x-card :title="$course->title" :body="$course->summary" />');

        $this->assertStringContainsString("'title' => \$course->title", $out);
        $this->assertStringContainsString("'body' => \$course->summary", $out);
    }

    public function test_a_chained_call_survives(): void
    {
        $out = $this->compile('<x-card :label="$course->category->name" />');

        $this->assertStringContainsString("'label' => \$course->category->name", $out);
    }

    public function test_a_nullsafe_arrow_survives(): void
    {
        $out = $this->compile('<x-card :label="$course?->category?->name" />');

        $this->assertStringContainsString("'label' => \$course?->category?->name", $out);
    }

    public function test_a_plain_string_attribute_still_works(): void
    {
        $out = $this->compile('<x-card title="Food Safety" />');

        $this->assertStringContainsString("'title' => 'Food Safety'", $out);
    }

    public function test_a_valueless_attribute_is_still_true(): void
    {
        $out = $this->compile('<x-card featured />');

        $this->assertStringContainsString("'featured' => true", $out);
    }

    public function test_the_compiled_output_is_valid_php(): void
    {
        // The real symptom was a parse error, so check the generated code
        // actually parses rather than only that it contains the right text.
        $out = $this->compile('<x-layout :title="$course->title" :cats="$cats">inner</x-layout>');

        $file = tempnam(sys_get_temp_dir(), 'blaze') . '.php';
        file_put_contents($file, $out);

        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);
        @unlink($file);

        $this->assertSame(0, $status, "Compiled template does not parse:\n" . implode("\n", $output));
    }
}
