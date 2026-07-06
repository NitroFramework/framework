<?php

namespace Tests\Unit\Console;

use Nitro\Console\Command;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the argv parser: value-mode options given bare, short
 * options carrying a value, and negative-number positional arguments.
 */
class OptionParsingTest extends TestCase
{
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

    public function test_bare_value_option_takes_default_not_true(): void
    {
        // --times has a value mode + default; passed bare it must be the default,
        // not boolean true (which would break a string consumer).
        $cmd = $this->command('greet {name} {--times=1}');
        $cmd->run(['Ada', '--times']);

        $this->assertSame('1', $cmd->option('times'));
    }

    public function test_short_option_with_value_is_parsed(): void
    {
        // -L=5 must set the value; previously short options only handled flags
        // and the value leaked into positional arguments.
        $cmd = $this->command('greet {name} {--L|limit=10}');
        $cmd->run(['Ada', '-L=5']);

        $this->assertSame('5', $cmd->option('limit'));
        $this->assertSame('Ada', $cmd->argument('name'));
    }

    public function test_negative_number_is_a_positional_argument(): void
    {
        // -5 must not be swallowed as an unknown short option.
        $cmd = $this->command('calc {n}');
        $cmd->run(['-5']);

        $this->assertSame('-5', $cmd->argument('n'));
    }

    public function test_short_flag_still_works(): void
    {
        $cmd = $this->command('greet {name} {--S|shout}');
        $cmd->run(['Ada', '-S']);

        $this->assertTrue($cmd->option('shout'));
    }
}
