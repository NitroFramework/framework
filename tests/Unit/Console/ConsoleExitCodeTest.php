<?php

namespace Tests\Unit\Console;

use Nitro\Console\CommandManager;
use Nitro\Console\Kernel;
use Nitro\Console\OutputFormatter;
use PHPUnit\Framework\TestCase;

/**
 * The console kernel must return the command's exit code so the shell/CI sees
 * success vs failure. Previously every path returned void → the process always
 * exited 0, even for a failed or missing command.
 */
class ConsoleExitCodeTest extends TestCase
{
    private function kernel(CommandManager $cm): Kernel
    {
        return new Kernel($this->createMock(OutputFormatter::class), $cm);
    }

    public function test_success_code_is_propagated(): void
    {
        $cm = $this->createMock(CommandManager::class);
        $cm->method('resolve')->willReturn(0);

        $this->assertSame(0, $this->kernel($cm)->run(['nitro', 'migrate']));
    }

    public function test_nonzero_command_code_is_propagated(): void
    {
        $cm = $this->createMock(CommandManager::class);
        $cm->method('resolve')->willReturn(2);

        $this->assertSame(2, $this->kernel($cm)->run(['nitro', 'migrate']));
    }

    public function test_thrown_error_yields_nonzero(): void
    {
        $cm = $this->createMock(CommandManager::class);
        $cm->method('resolve')->willThrowException(new \Exception('Command not found'));

        $this->assertSame(1, $this->kernel($cm)->run(['nitro', 'ghost']));
    }
}
