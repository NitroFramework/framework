<?php

namespace Tests\Unit\Console;

use Nitro\Console\Commands\DatabaseCommands;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\Config;
use PHPUnit\Framework\TestCase;

/**
 * Production-safety guard tests for db:wipe. The actual DELETE is
 * DB-bound and tested via manual usage — here we verify the env
 * check fires correctly so a forgetful operator can't accidentally
 * empty a prod table by typing `db:wipe users` and hitting Enter.
 */
class DatabaseCommandsTest extends TestCase
{
    public function test_wipe_refuses_in_production_without_force(): void
    {
        $cmd = new DatabaseCommands(
            new OutputFormatter(),
            Config::fromArray(['app' => ['env' => 'production']]),
        );

        ob_start();
        $cmd->handle('db:wipe', ['users']);
        $output = ob_get_clean();

        $this->assertStringContainsString('Refusing to wipe', $output);
        $this->assertStringContainsString('--force', $output);
    }

    public function test_wipe_refuses_message_includes_the_table_name(): void
    {
        $cmd = new DatabaseCommands(
            new OutputFormatter(),
            Config::fromArray(['app' => ['env' => 'production']]),
        );

        ob_start();
        $cmd->handle('db:wipe', ['orders']);
        $output = ob_get_clean();

        $this->assertStringContainsString("'orders'", $output,
            'error should name the offending table so the operator sees what was refused');
        $this->assertStringContainsString('db:wipe orders --force', $output,
            'error should show the literal re-run command');
    }

    public function test_show_requires_a_table_argument(): void
    {
        $cmd = new DatabaseCommands(
            new OutputFormatter(),
            Config::fromArray(['app' => ['env' => 'local']]),
        );

        ob_start();
        $cmd->handle('db:show', []);
        $output = ob_get_clean();

        $this->assertStringContainsString('Usage:', $output);
    }

    public function test_count_requires_a_table_argument(): void
    {
        $cmd = new DatabaseCommands(
            new OutputFormatter(),
            Config::fromArray(['app' => ['env' => 'local']]),
        );

        ob_start();
        $cmd->handle('db:count', []);
        $output = ob_get_clean();

        $this->assertStringContainsString('Usage:', $output);
    }
}
