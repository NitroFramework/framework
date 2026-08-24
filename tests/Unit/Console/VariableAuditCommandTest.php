<?php

namespace Tests\Unit\Console;

use Nitro\Console\Commands\VariableAuditCommand;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\PathRegistry;
use PHPUnit\Framework\TestCase;

/**
 * `nitro audit:variables` — the scan behind the naming cleanup.
 *
 * The reason it tokenizes rather than greps is the case covered below: the
 * container compiler EMITS PHP source containing '$c', and a regex would report
 * that string data as a variable. Equally, a variable interpolated into a
 * double-quoted string is a real use and must be counted.
 */
class VariableAuditCommandTest extends TestCase
{
    private string $dir;
    private string $out;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nitro-varaudit-' . getmypid();
        $this->out = $this->dir . '/report.txt';

        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function command(): VariableAuditCommand
    {
        return new VariableAuditCommand(new PathRegistry($this->dir), new OutputFormatter());
    }

    private function audit(array $arguments): string
    {
        ob_start();
        $this->command()->handle('audit:variables', $arguments);
        ob_end_clean();

        return (string) @file_get_contents($this->out);
    }

    private function write(string $name, string $php): void
    {
        file_put_contents($this->dir . '/' . $name, $php);
    }

    public function test_it_reports_a_short_variable_with_its_file_and_line(): void
    {
        $this->write('Sample.php', "<?php\n\$c = 1;\n\$c++;\n");

        $report = $this->audit([$this->dir, '--out=' . $this->out]);

        $this->assertStringContainsString('$c', $report);
        $this->assertStringContainsString('2 occurrence(s)', $report);
        $this->assertStringContainsString('Sample.php', $report);
    }

    public function test_it_ignores_a_variable_that_is_only_string_data(): void
    {
        // Exactly what ContainerCompiler emits: generated source, in quotes.
        $this->write('Generated.php', "<?php\n\$factory = 'static fn(\$c) => new Thing(\$c->get(1))';\n");

        $report = $this->audit([$this->dir, '--out=' . $this->out]);

        $this->assertStringNotContainsString('$c —', $report);
    }

    public function test_it_counts_a_variable_interpolated_into_a_string(): void
    {
        $this->write('Interp.php', "<?php\n\$v = 'x';\necho \"value: \$v\";\n");

        $report = $this->audit([$this->dir, '--out=' . $this->out]);

        $this->assertStringContainsString('$v', $report);
        $this->assertStringContainsString('2 occurrence(s)', $report);
    }

    public function test_it_never_reports_this(): void
    {
        $this->write('Uses.php', "<?php\nclass A { function b() { return \$this; } }\n");

        $this->assertStringNotContainsString('$this —', $this->audit([$this->dir, '--out=' . $this->out]));
    }

    public function test_names_that_are_already_clear_are_skipped_by_default(): void
    {
        $this->write('Clear.php', "<?php\n\$id = 1;\n\$ip = '127.0.0.1';\n\$q = 2;\n");

        $report = $this->audit([$this->dir, '--out=' . $this->out]);

        $this->assertStringNotContainsString('$id —', $report);
        $this->assertStringNotContainsString('$ip —', $report);
        // …but a genuinely opaque one still shows.
        $this->assertStringContainsString('$q —', $report);
    }

    public function test_all_includes_the_clear_names(): void
    {
        $this->write('Clear.php', "<?php\n\$id = 1;\n");

        $this->assertStringContainsString(
            '$id —',
            $this->audit([$this->dir, '--out=' . $this->out, '--all'])
        );
    }

    public function test_max_widens_what_counts_as_short(): void
    {
        $this->write('Wide.php', "<?php\n\$row = 1;\n");

        $this->assertStringNotContainsString('$row', $this->audit([$this->dir, '--out=' . $this->out]));
        $this->assertStringContainsString('$row', $this->audit([$this->dir, '--out=' . $this->out, '--max=3']));
    }

    public function test_line_numbers_are_collapsed_into_ranges(): void
    {
        $this->write('Runs.php', "<?php\n\$z = 1;\n\$z = 2;\n\$z = 3;\n\n\$z = 4;\n");

        $report = $this->audit([$this->dir, '--out=' . $this->out]);

        // Lines 2,3,4 are consecutive and 6 stands alone.
        $this->assertStringContainsString('2-4, 6', $report);
    }

    public function test_it_declares_the_command_signature(): void
    {
        $this->assertArrayHasKey('audit:variables', $this->command()->getCommands());
    }
}
