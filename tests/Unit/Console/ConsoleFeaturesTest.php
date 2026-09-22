<?php

namespace Tests\Unit\Console;

use Nitro\Cache\Drivers\ArrayStore;
use Nitro\Cache\Repository;
use Nitro\Console\Command;
use Nitro\Console\CommandLock;
use Nitro\Console\CommandManager;
use Nitro\Console\Contracts\Isolatable;
use Nitro\Console\Contracts\PromptsForMissingInput;
use Nitro\Console\Events\CommandEvent;
use Nitro\Console\Events\ConsoleEvents;
use Nitro\Console\GeneratorCommand;
use Nitro\Console\OutputFormatter;
use Nitro\Console\Support\Arguments;
use Nitro\Console\Support\Terminal;
use Nitro\Console\Verbosity;
use Nitro\Console\View\Components;
use Nitro\Container\Container;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Events\Dispatcher;
use Nitro\Foundation\PathRegistry;
use Nitro\Console\Support\Style;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The console features added to close the gap with Laravel: running one
 * command from another, verbosity, command events, isolation, prompting for a
 * missing argument, the progress bar, and the generator base class.
 */
class ConsoleFeaturesTest extends TestCase
{
    private function manager(array $commands, ?Repository $cache = null): CommandManager
    {
        $manager = new CommandManager(
            (new Container())->get(ClassResolver::class),
            new OutputFormatter(),
            new PathRegistry(sys_get_temp_dir())
        );

        $registry = new \ReflectionProperty($manager, 'commands');
        $registry->setValue($manager, $registry->getValue($manager) + $commands);

        if ($cache !== null) {
            $manager->setLockStore($cache);
        }

        return $manager;
    }

    // ─── Verbosity ──────────────────────────────────────────────────────────

    public function test_verbosity_is_read_from_the_arguments(): void
    {
        $this->assertSame(Verbosity::Normal, Verbosity::fromArguments([]));
        $this->assertSame(Verbosity::Verbose, Verbosity::fromArguments(['-v']));
        $this->assertSame(Verbosity::VeryVerbose, Verbosity::fromArguments(['-vv']));
        $this->assertSame(Verbosity::Debug, Verbosity::fromArguments(['-vvv']));
        $this->assertSame(Verbosity::Quiet, Verbosity::fromArguments(['-q']));
    }

    /** -v must not reach the command's own signature parser. */
    public function test_verbosity_flags_are_stripped_from_the_arguments(): void
    {
        $this->assertSame(['alice', '--force'], Verbosity::strip(['alice', '-vv', '--force']));
    }

    public function test_a_level_only_shows_output_at_or_below_it(): void
    {
        $this->assertTrue(Verbosity::Verbose->allows(Verbosity::Normal));
        $this->assertTrue(Verbosity::Verbose->allows(Verbosity::Verbose));
        $this->assertFalse(Verbosity::Verbose->allows(Verbosity::Debug));
        $this->assertFalse(Verbosity::Quiet->allows(Verbosity::Normal));
    }

    // ─── Running one command from another ───────────────────────────────────

    public function test_a_command_can_run_another_and_gets_its_exit_code(): void
    {
        $inner = new ConsoleFeaturesInner();
        $outer = new ConsoleFeaturesOuter();

        $manager = $this->manager(['inner' => $inner, 'outer' => $outer]);

        ob_start();
        $manager->resolve('outer', []);
        ob_end_clean();

        $this->assertSame(1, $inner->runs);
        $this->assertSame(7, $outer->innerCode, 'the inner exit code must reach the caller');
    }

    public function test_call_silently_runs_the_command_at_quiet(): void
    {
        $inner = new ConsoleFeaturesInner();
        $outer = new ConsoleFeaturesOuter();
        $outer->silently = true;

        $manager = $this->manager(['inner' => $inner, 'outer' => $outer]);

        ob_start();
        $manager->resolve('outer', []);
        ob_end_clean();

        $this->assertSame(1, $inner->runs);
        $this->assertSame(Verbosity::Quiet, $inner->ranAt);
    }

    #[DataProvider('argumentSpellings')]
    public function test_arguments_may_be_written_either_way(array $given, array $expected): void
    {
        $this->assertSame($expected, Arguments::flatten($given));
    }

    public static function argumentSpellings(): array
    {
        return [
            'boolean option'   => [['--force' => true], ['--force']],
            'option value'     => [['--queue' => 'high'], ['--queue=high']],
            'array option'     => [['--id' => [1, 2]], ['--id=1', '--id=2']],
            'positional'       => [['user' => 5], ['5']],
            'false is dropped' => [['--skip' => false], []],
            'already a list'   => [['--force'], ['--force']],
        ];
    }

    // ─── Events ─────────────────────────────────────────────────────────────

    public function test_a_command_raises_starting_and_finished(): void
    {
        $seen = [];
        $events = new Dispatcher();

        $events->listen(ConsoleEvents::STARTING, function (CommandEvent $e) use (&$seen): void {
            $seen['starting'] = $e;
        });
        $events->listen(ConsoleEvents::FINISHED, function (CommandEvent $e) use (&$seen): void {
            $seen['finished'] = $e;
        });

        $manager = $this->manager(['inner' => new ConsoleFeaturesInner()]);
        $manager->setDispatcher($events);

        ob_start();
        $manager->resolve('inner', []);
        ob_end_clean();

        $this->assertNull($seen['starting']->exitCode, 'nothing has run yet at starting');
        $this->assertSame(7, $seen['finished']->exitCode);
        $this->assertFalse($seen['finished']->succeeded());
    }

    /** A failure is the run most worth hearing about, so it must still report. */
    public function test_a_throwing_command_still_reports_finished(): void
    {
        $finished = null;
        $events = new Dispatcher();

        $events->listen(ConsoleEvents::FINISHED, function (CommandEvent $e) use (&$finished): void {
            $finished = $e;
        });

        $manager = $this->manager(['boom' => new ConsoleFeaturesBoom()]);
        $manager->setDispatcher($events);

        try {
            ob_start();
            $manager->resolve('boom', []);
        } catch (\RuntimeException) {
            // expected
        } finally {
            ob_end_clean();
        }

        $this->assertNotNull($finished, 'command.finished must fire even when the command throws');
        $this->assertNotSame(0, $finished->exitCode);
    }

    // ─── Isolation ──────────────────────────────────────────────────────────

    public function test_an_isolated_command_does_not_run_while_the_lock_is_held(): void
    {
        $cache = new Repository(new ArrayStore());
        $command = new ConsoleFeaturesIsolated();

        $manager = $this->manager(['nightly' => $command], $cache);

        $held = new CommandLock($cache, 'nightly', 60);
        $this->assertTrue($held->acquire());

        ob_start();
        $code = $manager->resolve('nightly', ['--isolated']);
        ob_end_clean();

        $this->assertSame(0, $command->runs, 'the command must not have run while the lock was held');
        $this->assertSame(0, $code, 'a held lock is success: the work is already in hand');

        $held->release();

        ob_start();
        $manager->resolve('nightly', ['--isolated']);
        ob_end_clean();

        $this->assertSame(1, $command->runs);
    }

    public function test_only_the_holder_can_release_a_lock(): void
    {
        $cache = new Repository(new ArrayStore());

        $holder = new CommandLock($cache, 'shared', 60);
        $other = new CommandLock($cache, 'shared', 60);

        $this->assertTrue($holder->acquire());
        $this->assertFalse($other->acquire());

        // The one that never held it must not be able to free it.
        $other->release();

        $this->assertFalse((new CommandLock($cache, 'shared', 60))->acquire());
    }

    public function test_isolated_without_a_cache_refuses_rather_than_running(): void
    {
        $command = new ConsoleFeaturesIsolated();
        $manager = $this->manager(['nightly' => $command]);

        ob_start();
        $code = $manager->resolve('nightly', ['--isolated']);
        ob_end_clean();

        $this->assertNotSame(0, $code);
        $this->assertSame(0, $command->runs, 'running unlocked is worse than not running');
    }

    // ─── Prompting ──────────────────────────────────────────────────────────

    /**
     * The case that must never happen: a prompt where nothing can answer.
     * The test suite has no terminal, which is exactly the CI condition.
     */
    public function test_a_missing_argument_fails_rather_than_prompting_without_a_terminal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments');

        ob_start();

        try {
            (new ConsoleFeaturesPrompting())->run([]);
        } finally {
            ob_end_clean();
        }
    }

    public function test_a_supplied_argument_is_never_prompted_for(): void
    {
        $command = new ConsoleFeaturesPrompting();

        ob_start();
        $command->run(['alice']);
        ob_end_clean();

        $this->assertSame('alice', $command->name);
    }

    // ─── Progress bar ───────────────────────────────────────────────────────

    public function test_the_progress_bar_runs_the_callback_and_draws_nothing_without_a_terminal(): void
    {
        $components = new ConsoleFeaturesSpyComponents(new Style(false));

        $results = $components->withProgressBar([1, 2, 3], fn (int $n): int => $n * 10);

        $this->assertSame([10, 20, 30], $results);
        $this->assertSame('', $components->written, 'redraw sequences must not reach a log');
    }

    public function test_the_progress_bar_draws_on_a_terminal(): void
    {
        $components = new ConsoleFeaturesSpyComponents(new Style(true));

        $components->withProgressBar([1, 2], fn (int $n): int => $n);

        $this->assertStringContainsString('100%', $components->written);
    }

    // ─── Colour and terminal detection ──────────────────────────────────────

    /**
     * Escape sequences belong on a terminal and nowhere else. Redirected
     * output — `nitro migrate > deploy.log` — should leave a file that reads.
     */
    public function test_the_output_formatter_colours_only_when_decorated(): void
    {
        $this->assertStringContainsString("\033[", (new OutputFormatter(true))->color('x', 'green'));
        $this->assertSame('x', (new OutputFormatter(false))->color('x', 'green'));
    }

    /** Built without a constructor in places, and must still print. */
    public function test_the_output_formatter_survives_a_bypassed_constructor(): void
    {
        $formatter = (new \ReflectionClass(OutputFormatter::class))->newInstanceWithoutConstructor();

        $this->assertIsString($formatter->color('x', 'green'));
    }

    public function test_style_colours_only_when_decorated(): void
    {
        $this->assertStringContainsString("\033[", (new Style(true))->format('<info>x</info>'));
        $this->assertStringNotContainsString("\033[", (new Style(false))->format('<info>x</info>'));
    }

    // ─── Prompts ────────────────────────────────────────────────────────────

    /**
     * The suite has no terminal, which is the CI condition. Every prompt must
     * return on its own rather than waiting for input that will never come —
     * a blocked prompt is a job that hangs until something kills it.
     */
    public function test_no_prompt_blocks_without_a_terminal(): void
    {
        $components = new ConsoleFeaturesQuietComponents(new Style(false));

        $start = microtime(true);

        $components->select('Colour?', ['red', 'green'], 0);
        $components->multiselect('Colours?', ['red', 'green'], ['red']);
        $components->ask('Name?', 'default');
        $components->confirm('Sure?', true);
        $components->secret('Password?');

        $this->assertLessThan(
            2.0,
            microtime(true) - $start,
            'a prompt with nothing to read must return, not wait'
        );
    }

    public function test_raw_mode_is_refused_without_a_terminal(): void
    {
        $this->assertFalse(Terminal::hasInputTerminal());
        $this->assertFalse(Terminal::supportsRawMode());
    }

    /**
     * Hiding input starts PowerShell on Windows, whose Read-Host waits for a
     * keypress a redirected stdin never delivers. Answered before it starts.
     */
    public function test_hidden_input_is_not_attempted_without_a_terminal(): void
    {
        $this->assertNull(Terminal::readHidden());
    }

    /** Restoring a terminal nobody changed must not shell out at all. */
    public function test_restoring_an_untouched_terminal_is_a_no_op(): void
    {
        $terminal = new Terminal();

        ob_start();
        $terminal->restore();
        $noise = ob_get_clean();

        $this->assertSame('', $noise);
    }

    public function test_select_falls_back_to_the_numbered_prompt(): void
    {
        $components = new ConsoleFeaturesQuietComponents(new Style(false));

        // No input to read, so the default is what comes back.
        $this->assertSame('red', $components->select('Colour?', ['red', 'green'], 0));
    }

    public function test_ask_stops_asking_when_there_is_nothing_to_read(): void
    {
        $components = new ConsoleFeaturesQuietComponents(new Style(false));

        $calls = 0;

        $components->ask('Email?', null, function (string $value) use (&$calls): ?string {
            $calls++;

            return 'never valid';
        });

        $this->assertLessThanOrEqual(3, $calls, 'a failing validator must not loop forever');
    }

    // ─── Generator ──────────────────────────────────────────────────────────

    public function test_a_generator_writes_a_class_that_parses(): void
    {
        $root = sys_get_temp_dir() . '/nitro-gen-' . uniqid();
        mkdir($root);

        ob_start();
        $code = (new ConsoleFeaturesMakeService(new PathRegistry($root)))->run(['billing/monthly-report'], Verbosity::Quiet);
        ob_end_clean();

        $path = $root . '/app/Services/Billing/MonthlyReportService.php';

        $this->assertSame(0, $code);
        $this->assertFileExists($path, 'a hyphenated name must become a studly class name');

        $contents = (string) file_get_contents($path);

        $this->assertStringContainsString('namespace App\Services\Billing;', $contents);
        $this->assertStringContainsString('class MonthlyReportService', $contents);

        $this->cleanUp($root);
    }

    public function test_a_generator_refuses_to_overwrite_without_force(): void
    {
        $root = sys_get_temp_dir() . '/nitro-gen-' . uniqid();
        mkdir($root);

        ob_start();
        (new ConsoleFeaturesMakeService(new PathRegistry($root)))->run(['invoice'], Verbosity::Quiet);
        $second = (new ConsoleFeaturesMakeService(new PathRegistry($root)))->run(['invoice'], Verbosity::Quiet);
        $forced = (new ConsoleFeaturesMakeService(new PathRegistry($root)))->run(['invoice', '--force'], Verbosity::Quiet);
        ob_end_clean();

        $this->assertNotSame(0, $second);
        $this->assertSame(0, $forced);

        $this->cleanUp($root);
    }

    public function test_a_generator_does_not_double_the_suffix(): void
    {
        $root = sys_get_temp_dir() . '/nitro-gen-' . uniqid();
        mkdir($root);

        ob_start();
        (new ConsoleFeaturesMakeService(new PathRegistry($root)))->run(['PayoutService'], Verbosity::Quiet);
        ob_end_clean();

        $this->assertFileExists($root . '/app/Services/PayoutService.php');
        $this->assertFileDoesNotExist($root . '/app/Services/PayoutServiceService.php');

        $this->cleanUp($root);
    }

    private function cleanUp(string $root): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($root);
    }
}

class ConsoleFeaturesInner extends Command
{
    protected string $signature = 'inner';
    protected string $description = '';

    public int $runs = 0;
    public ?Verbosity $ranAt = null;

    public function handle(): int
    {
        $this->runs++;
        $this->ranAt = $this->verbosity;
        $this->line('inner ran');

        return 7;
    }
}

class ConsoleFeaturesOuter extends Command
{
    protected string $signature = 'outer';
    protected string $description = '';

    public bool $silently = false;
    public int $innerCode = -1;

    public function handle(): int
    {
        $this->innerCode = $this->silently
            ? $this->callSilently('inner')
            : $this->call('inner');

        return 0;
    }
}

class ConsoleFeaturesBoom extends Command
{
    protected string $signature = 'boom';
    protected string $description = '';

    public function handle(): int
    {
        throw new \RuntimeException('exploded');
    }
}

class ConsoleFeaturesIsolated extends Command implements Isolatable
{
    protected string $signature = 'nightly';
    protected string $description = '';

    public int $runs = 0;

    public function handle(): int
    {
        $this->runs++;

        return 0;
    }

    public function isolationSeconds(): int
    {
        return 60;
    }
}

class ConsoleFeaturesPrompting extends Command implements PromptsForMissingInput
{
    protected string $signature = 'greets {name}';
    protected string $description = '';

    public string $name = '';

    public function handle(): int
    {
        $this->name = (string) $this->argument('name');

        return 0;
    }

    public function promptForMissingArgumentsUsing(): array
    {
        return ['name' => 'Who should I greet?'];
    }
}

/** Swallows output entirely, for tests that only care about timing. */
class ConsoleFeaturesQuietComponents extends Components
{
    protected function write(string $text, Verbosity $level = Verbosity::Normal): void
    {
    }
}

/** Captures what would have been written, instead of reaching STDOUT. */
class ConsoleFeaturesSpyComponents extends Components
{
    public string $written = '';

    protected function write(string $text, Verbosity $level = Verbosity::Normal): void
    {
        if (! $this->verbosity()->allows($level)) {
            return;
        }

        $this->written .= $text;
    }
}

class ConsoleFeaturesMakeService extends GeneratorCommand
{
    protected string $signature = 'make:service {name} {--force}';
    protected string $description = '';

    protected function namespace(): string
    {
        return 'App\Services';
    }

    protected function suffix(): string
    {
        return 'Service';
    }

    protected function stub(): string
    {
        return "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }}\n{\n}\n";
    }
}
