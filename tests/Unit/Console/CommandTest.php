<?php

namespace Tests\Unit\Console;

use Nitro\Console\Command;
use Nitro\Console\Support\SignatureParser;
use Nitro\Console\Support\Style;
use PHPUnit\Framework\TestCase;

/**
 * The Laravel-style command layer: signature parsing, argument/option binding,
 * and the ANSI style formatter.
 */
class CommandTest extends TestCase
{
    // ─── Signature parsing ──────────────────────────────────────────────────

    public function test_parses_name_arguments_and_options(): void
    {
        $def = SignatureParser::parse('reports:send {user} {ids?*} {--queue} {--limit=10} {--Q|quiet}');

        $this->assertSame('reports:send', $def['name']);

        $this->assertSame('user', $def['arguments'][0]['name']);
        $this->assertSame('required', $def['arguments'][0]['mode']);
        $this->assertSame('ids', $def['arguments'][1]['name']);
        $this->assertSame('array', $def['arguments'][1]['mode']);

        $opts = [];
        foreach ($def['options'] as $o) {
            $opts[$o['name']] = $o;
        }
        $this->assertSame('none', $opts['queue']['mode']);
        $this->assertSame('value', $opts['limit']['mode']);
        $this->assertSame('10', $opts['limit']['default']);
        $this->assertSame('Q', $opts['quiet']['shortcut']);
    }

    public function test_parses_optional_and_default_arguments_and_descriptions(): void
    {
        $def = SignatureParser::parse('x {name? : the name} {role=guest}');

        $this->assertSame('optional', $def['arguments'][0]['mode']);
        $this->assertSame('the name', $def['arguments'][0]['description']);
        $this->assertSame('optional', $def['arguments'][1]['mode']);
        $this->assertSame('guest', $def['arguments'][1]['default']);
    }

    // ─── Input binding (via a real Command) ─────────────────────────────────

    public function test_binds_positional_arguments_and_options(): void
    {
        $cmd = $this->command('greet {name} {--shout} {--times=1}');
        $cmd->run(['Ada', '--shout', '--times=3']);

        $this->assertSame('Ada', $cmd->argument('name'));
        $this->assertTrue($cmd->option('shout'));
        $this->assertSame('3', $cmd->option('times'));
    }

    public function test_defaults_apply_when_not_passed(): void
    {
        $cmd = $this->command('greet {name} {--times=1}');
        $cmd->run(['Ada']);

        $this->assertFalse($cmd->option('shout') ?? false); // undefined option
        $this->assertSame('1', $cmd->option('times'));
    }

    public function test_array_argument_collects_the_rest(): void
    {
        $cmd = $this->command('sum {label} {numbers*}');
        $cmd->run(['total', '1', '2', '3']);

        $this->assertSame('total', $cmd->argument('label'));
        $this->assertSame(['1', '2', '3'], $cmd->argument('numbers'));
    }

    public function test_missing_required_argument_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->command('greet {name}')->run([]);
    }

    // ─── Style formatting ───────────────────────────────────────────────────

    public function test_style_emits_ansi_when_decorated(): void
    {
        $out = (new Style(true))->format('<info>ok</info> <fg=red;options=bold>bad</>');

        $this->assertStringContainsString("\e[", $out);
        $this->assertStringContainsString('ok', $out);
    }

    public function test_style_strips_tags_when_not_decorated(): void
    {
        $this->assertSame('ok bad', (new Style(false))->format('<info>ok</info> <fg=red>bad</>'));
    }

    public function test_style_width_ignores_tags_and_ansi(): void
    {
        $this->assertSame(5, Style::width('<fg=green>hello</>'));
    }

    private function command(string $signature): Command
    {
        return new class($signature) extends Command {
            public function __construct(string $sig)
            {
                $this->signature = $sig;
            }

            public function handle(): int
            {
                return 0;
            }
        };
    }
}
