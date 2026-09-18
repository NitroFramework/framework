<?php

namespace Tests\Unit\Process;

use Nitro\Process\Factory;
use Nitro\Process\ProcessFailedException;
use PHPUnit\Framework\TestCase;

/**
 * Running external commands, and faking them.
 */
class ProcessTest extends TestCase
{
    private Factory $process;

    protected function setUp(): void
    {
        parent::setUp();

        $this->process = new Factory();
    }

    // ─── Faking ───────────────────────────────────────────

    public function test_a_faked_command_returns_its_stub(): void
    {
        $this->process->fake(['git *' => $this->process->result("nothing to commit\n")]);

        $result = $this->process->run('git status');

        $this->assertTrue($result->successful());
        $this->assertFalse($result->failed());
        $this->assertSame("nothing to commit\n", $result->output());
        $this->assertTrue($result->seeInOutput('nothing'));
        $this->assertSame('git status', $result->command());
    }

    public function test_a_stub_may_be_a_closure_over_the_command(): void
    {
        $this->process->fake([
            '*' => fn (array $process) => $this->process->result($process['command']),
        ]);

        $this->assertSame('echo hello', $this->process->run('echo hello')->output());
    }

    public function test_a_stub_may_be_a_plain_string(): void
    {
        $this->process->fake(['*' => fn (): string => 'plain']);

        $this->assertSame('plain', $this->process->run('anything')->output());
    }

    public function test_patterns_choose_between_stubs(): void
    {
        $this->process->fake([
            'git *' => $this->process->result('from git'),
            '*' => $this->process->result('from anything'),
        ]);

        $this->assertSame('from git', $this->process->run('git log')->output());
        $this->assertSame('from anything', $this->process->run('ls')->output());
    }

    public function test_stray_commands_can_be_prevented(): void
    {
        $this->process->fake(['git *' => $this->process->result('ok')])
            ->preventStrayProcesses();

        $this->assertSame('ok', $this->process->run('git log')->output());

        $this->expectException(\RuntimeException::class);

        $this->process->run('rm -rf /');
    }

    public function test_faking_resets_what_was_recorded(): void
    {
        $this->process->fake();
        $this->process->run('ls');
        $this->process->assertRanCount(1);

        $this->process->fake();

        $this->process->assertNothingRan();
    }

    // ─── Failure ──────────────────────────────────────────

    public function test_a_non_zero_exit_is_a_failure(): void
    {
        $this->process->fake(['*' => $this->process->result('', 1, 'boom')]);

        $result = $this->process->run('false');

        $this->assertTrue($result->failed());
        $this->assertSame(1, $result->exitCode());
        $this->assertSame('boom', $result->errorOutput());
        $this->assertTrue($result->seeInErrorOutput('boom'));
    }

    public function test_throw_raises_on_failure(): void
    {
        $this->process->fake(['*' => $this->process->result('', 2, 'went wrong')]);

        $result = $this->process->run('false');

        $this->expectException(ProcessFailedException::class);
        $this->expectExceptionMessage('exit code 2');

        $result->throw();
    }

    public function test_throw_is_a_no_op_on_success(): void
    {
        $this->process->fake(['*' => $this->process->result('fine')]);

        $this->assertSame('fine', $this->process->run('true')->throw()->output());
    }

    public function test_the_exception_carries_the_result(): void
    {
        $this->process->fake(['*' => $this->process->result('out', 3, 'err')]);

        try {
            $this->process->run('false')->throw();
            $this->fail('expected a ProcessFailedException');
        } catch (ProcessFailedException $exception) {
            $this->assertSame(3, $exception->result->exitCode());
            $this->assertSame('out', $exception->result->output());
        }
    }

    // ─── Configuration ────────────────────────────────────

    public function test_configuration_reaches_the_definition(): void
    {
        $this->process->fake();

        $this->process
            ->path('/tmp')
            ->timeout(15)
            ->env(['APP_ENV' => 'testing'])
            ->input('stdin text')
            ->run('ls -la');

        $this->process->assertRan(fn (array $p): bool
            => $p['command'] === 'ls -la'
            && $p['directory'] === '/tmp'
            && $p['timeout'] === 15
            && $p['environment'] === ['APP_ENV' => 'testing']
            && $p['input'] === 'stdin text');
    }

    public function test_forever_removes_the_time_limit(): void
    {
        $this->process->fake();

        $this->process->forever()->run('sleep 1');

        $this->process->assertRan(fn (array $p): bool => $p['timeout'] === 0);
    }

    /** A fresh description each time: settings must not leak between runs. */
    public function test_configuration_does_not_leak_between_runs(): void
    {
        $this->process->fake();

        $this->process->path('/tmp')->run('one');
        $this->process->run('two');

        $this->process->assertRan(fn (array $p): bool
            => $p['command'] === 'two' && $p['directory'] === null);
    }

    public function test_a_command_given_as_an_array_is_joined(): void
    {
        $this->process->fake();

        $this->process->run(['git', 'status']);

        $this->process->assertRan(fn (array $p): bool => $p['command'] === 'git status');
    }

    // ─── Assertions ───────────────────────────────────────

    public function test_assertions_about_what_ran(): void
    {
        $this->process->fake();

        $this->process->run('one');
        $this->process->run('two');

        $this->process->assertRanCount(2);
        $this->process->assertDidntRun(fn (array $p): bool => $p['command'] === 'three');
        $this->assertCount(2, $this->process->recorded());
    }

    public function test_assert_ran_fails_when_nothing_matched(): void
    {
        $this->process->fake();
        $this->process->run('one');

        $this->expectException(\RuntimeException::class);

        $this->process->assertRan(fn (array $p): bool => $p['command'] === 'other');
    }

    public function test_assert_nothing_ran(): void
    {
        $this->process->fake();

        $this->process->assertNothingRan();

        $this->process->run('one');

        $this->expectException(\RuntimeException::class);

        $this->process->assertNothingRan();
    }

    // ─── Actually running ─────────────────────────────────

    public function test_a_real_command_runs_and_returns_its_output(): void
    {
        $result = $this->process->run(PHP_BINARY . ' -r "echo 42;"');

        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->assertSame('42', trim($result->output()));
        $this->assertSame(0, $result->exitCode());
    }

    public function test_a_real_command_reports_its_exit_code(): void
    {
        $result = $this->process->run(PHP_BINARY . ' -r "exit(3);"');

        $this->assertTrue($result->failed());
        $this->assertSame(3, $result->exitCode());
    }

    public function test_standard_input_reaches_a_real_command(): void
    {
        $result = $this->process->input('from stdin')
            ->run(PHP_BINARY . ' -r "echo stream_get_contents(STDIN);"');

        $this->assertSame('from stdin', trim($result->output()));
    }

    public function test_quietly_discards_the_output(): void
    {
        $result = $this->process->quietly()->run(PHP_BINARY . ' -r "echo 42;"');

        $this->assertSame('', $result->output());
        $this->assertTrue($result->successful());
    }
}
