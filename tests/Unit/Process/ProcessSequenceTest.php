<?php

namespace Tests\Unit\Process;

use Nitro\Process\Factory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Answering a command differently each run, and asserting on the order.
 *
 * A single stub answers every call identically, which cannot express the case
 * a retry exists for — fails, then succeeds — so testing a retry meant
 * asserting it ran twice rather than that it recovered. Order matters for the
 * same reason: a deploy is install before build before restart, and asserting
 * each command separately says nothing about the sequence.
 */
class ProcessSequenceTest extends TestCase
{
    private Factory $process;

    protected function setUp(): void
    {
        parent::setUp();

        $this->process = new Factory();
    }

    // ─── A sequence of answers ────────────────────────────

    public function test_the_same_command_answers_differently_each_run(): void
    {
        $this->process->fake(['git pull' => $this->process->sequence()
            ->push($this->process->result(exitCode: 1))
            ->push($this->process->result('Already up to date.'))]);

        $this->assertSame(1, $this->process->run('git pull')->exitCode());
        $this->assertSame('Already up to date.', trim($this->process->run('git pull')->output()));
    }

    public function test_running_out_of_results_says_what_to_do(): void
    {
        $this->process->fake(['x' => $this->process->sequence()->push($this->process->result('one'))]);

        $this->process->run('x');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/dontFailWhenEmpty/');

        $this->process->run('x');
    }

    public function test_when_empty_answers_instead_of_failing(): void
    {
        $this->process->fake(['x' => $this->process->sequence()
            ->push($this->process->result('first'))
            ->whenEmpty($this->process->result('after'))]);

        $this->assertSame('first', trim($this->process->run('x')->output()));
        $this->assertSame('after', trim($this->process->run('x')->output()));
        $this->assertSame('after', trim($this->process->run('x')->output()));
    }

    public function test_dont_fail_when_empty_keeps_answering(): void
    {
        $this->process->fake(['x' => $this->process->sequence()
            ->push($this->process->result('first'))
            ->dontFailWhenEmpty()]);

        $this->process->run('x');

        $this->assertSame(0, $this->process->run('x')->exitCode());
    }

    public function test_a_sequence_may_be_built_from_an_array(): void
    {
        $this->process->fake(['x' => $this->process->sequence([
            $this->process->result('one'),
            $this->process->result('two'),
        ])]);

        $this->assertSame('one', trim($this->process->run('x')->output()));
        $this->assertSame('two', trim($this->process->run('x')->output()));
    }

    // ─── Order ────────────────────────────────────────────

    public function test_assert_ran_in_order_passes_when_the_order_held(): void
    {
        $this->process->fake();

        $this->process->run('composer install');
        $this->process->run('npm run build');
        $this->process->run('php nitro migrate');

        $this->process->assertRanInOrder(['composer install', 'php nitro migrate']);

        $this->addToAssertionCount(1);
    }

    public function test_assert_ran_in_order_allows_others_in_between(): void
    {
        $this->process->fake();

        $this->process->run('a');
        $this->process->run('unrelated');
        $this->process->run('b');

        $this->process->assertRanInOrder(['a', 'b']);

        $this->addToAssertionCount(1);
    }

    public function test_assert_ran_in_order_reports_what_ran_instead(): void
    {
        $this->process->fake();

        $this->process->run('php nitro migrate');
        $this->process->run('composer install');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/What ran/');

        $this->process->assertRanInOrder(['composer install', 'php nitro migrate']);
    }

    // ─── Counting ─────────────────────────────────────────

    public function test_assert_ran_times_and_assert_not_ran(): void
    {
        $this->process->fake();

        $this->process->run('ls');
        $this->process->run('ls');

        $this->process->assertRanTimes(2);
        $this->process->assertNotRan(static fn (array $process): bool => $process['command'] === 'rm -rf /');

        $this->addToAssertionCount(2);
    }
}
