<?php

namespace Tests\Unit\Console;

use Nitro\Console\Commands\ViewCommands;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * `view:cache` called `printSummary()` without the method ever being written,
 * so the command compiled every view and then died with "Call to undefined
 * method" on the last line. The views were cached; the command still reported
 * failure. These tests pin the method's existence and its two branches.
 */
class ViewCacheSummaryTest extends TestCase
{
    private function summarise(int $cached, int $failed, array $failedViews): string
    {
        // The command's collaborators are irrelevant to the summary; only the
        // output formatter is touched, and that writes straight to stdout.
        $command = (new \ReflectionClass(ViewCommands::class))->newInstanceWithoutConstructor();

        $output = new \ReflectionProperty(ViewCommands::class, 'output');
        $output->setValue($command, new \Nitro\Console\OutputFormatter());

        $method = new ReflectionMethod(ViewCommands::class, 'printSummary');

        ob_start();
        $method->invoke($command, $cached, $failed, $failedViews);

        return (string) ob_get_clean();
    }

    public function test_the_method_the_command_calls_actually_exists(): void
    {
        $this->assertTrue(
            method_exists(ViewCommands::class, 'printSummary'),
            'view:cache calls printSummary() and fatals without it.'
        );
    }

    public function test_it_reports_the_count_when_every_view_compiled(): void
    {
        $summary = $this->summarise(12, 0, []);

        $this->assertStringContainsString('12', $summary);
        $this->assertStringNotContainsString('failed', strtolower($summary));
    }

    public function test_it_names_each_failed_view_and_its_error(): void
    {
        $summary = $this->summarise(3, 1, [
            ['view' => 'orders.index', 'error' => 'Unexpected end of file'],
        ]);

        $this->assertStringContainsString('3', $summary);
        $this->assertStringContainsString('1 failed', $summary);
        $this->assertStringContainsString('orders.index', $summary);
        $this->assertStringContainsString('Unexpected end of file', $summary);
    }
}
