<?php

namespace Tests\Unit\Console;

use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\ExitCode;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Every grouped command must be able to report failure.
 *
 * handle() returned void until this was written, so CommandManager had nothing
 * to propagate and returned 0 whatever the command printed: a refused
 * `db:wipe` in production and a failed migration both looked like success to
 * CI. The contract now returns int, and these hold that line.
 *
 * The fall-through case is the one worth testing rather than reading for.
 * Falling out of a function declared `: int` is a runtime TypeError, not a
 * compile error, so `php -l` passes and the failure only appears when somebody
 * runs the command — which is how `nitro help` came to exit 1.
 */
class CommandExitCodeTest extends TestCase
{
    /**
     * Every command class the framework ships.
     *
     * @return array<int, class-string<CommandInterface>>
     */
    private function commandClasses(): array
    {
        $found = [];
        $root = dirname(__DIR__, 3) . '/src';

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! preg_match('/^namespace ([^;]+);/m', $source, $ns)) {
                continue;
            }
            if (! preg_match('/^(?:final )?class (\w+)[^{]*implements[^{]*CommandInterface/m', $source, $cls)) {
                continue;
            }

            $class = $ns[1] . '\\' . $cls[1];

            if (class_exists($class) && is_subclass_of($class, CommandInterface::class)) {
                $found[] = $class;
            }
        }

        sort($found);

        return $found;
    }

    public function test_the_framework_ships_commands_to_check(): void
    {
        $this->assertGreaterThan(15, count($this->commandClasses()));
    }

    public function test_every_command_declares_an_int_return(): void
    {
        foreach ($this->commandClasses() as $class) {
            $return = (new ReflectionMethod($class, 'handle'))->getReturnType();

            $this->assertNotNull($return, "{$class}::handle() has no return type");
            $this->assertSame(
                'int',
                (string) $return,
                "{$class}::handle() must return int so a failure reaches the shell"
            );
        }
    }

    /**
     * The dispatch path has to end in a return on every branch, including the
     * unknown-signature one. Calling with a signature no command answers to
     * exercises that branch without doing any of the command's real work.
     */
    public function test_an_unknown_signature_returns_a_non_zero_code(): void
    {
        $skipped = [];

        foreach ($this->commandClasses() as $class) {
            // Only commands that branch on the signature have an unknown branch
            // to exercise. One that ignores it — a single verb, or two names for
            // the same verb — would simply run, and this test would be creating
            // migrations. Its return type is covered by the test above.
            if (! $this->dispatchesOnSignature($class)) {
                continue;
            }

            $command = $this->build($class);

            if ($command === null) {
                $skipped[] = $class;
                continue;
            }

            ob_start();
            try {
                $code = $command->handle('no:such:signature', []);
            } catch (\Throwable $exception) {
                ob_end_clean();
                $this->fail("{$class}::handle() threw on an unknown signature: " . $exception->getMessage());
            }
            ob_end_clean();

            $this->assertIsInt($code, "{$class}::handle() did not return an int");
            $this->assertNotSame(
                ExitCode::SUCCESS,
                $code,
                "{$class}::handle() reported success for a signature it does not handle"
            );
        }

        // A command whose constructor needs a booted application is not
        // checkable here; it is named rather than silently passed over.
        $this->assertLessThan(
            count($this->commandClasses()),
            count($skipped),
            'no command could be constructed — the check proved nothing'
        );
    }

    /**
     * No `: int` method may fall off its end.
     *
     * This is the case a test has to cover rather than a reader: falling out of
     * a function declared `: int` is a runtime TypeError, so `php -l` is happy
     * and the command works right up until somebody runs it. `nitro help` and
     * `nitro lifecycle` both shipped that way for exactly one afternoon.
     */
    public function test_no_command_method_can_fall_off_its_end(): void
    {
        $offenders = [];

        foreach ($this->commandFiles() as $file) {
            foreach ($this->fallThroughMethods($file) as $method) {
                $offenders[] = str_replace(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR, '', $file) . ' — ' . $method;
            }
        }

        $this->assertSame([], $offenders, "these methods return int on some paths and nothing on others:\n  "
            . implode("\n  ", $offenders));
    }

    /**
     * Source files that declare a command.
     *
     * @return array<int, string>
     */
    private function commandFiles(): array
    {
        $files = [];
        $root = dirname(__DIR__, 3) . '/src';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (str_contains($source, 'ExitCode::') || str_contains($source, 'implements CommandInterface')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Methods in a file that declare `: int` but can reach their closing brace.
     *
     * Walks statements rather than tokens, so `return ExitCode::X;` is not read
     * as ending in a T_STRING. Counts T_CURLY_OPEN too — "{$var}" opens with a
     * token and closes with a plain '}', which otherwise drives the depth
     * negative and ends the walk inside the method.
     *
     * @return array<int, string>
     */
    private function fallThroughMethods(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $count = count($tokens);
        $found = [];

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            $name = null;
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) { $name = $tokens[$j][1]; break; }
                if ($tokens[$j] === '(') break;
            }
            if ($name === null) continue;

            $isInt = false;
            $parens = 0;
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j] === '(') { $parens++; continue; }
                if ($tokens[$j] === ')') { $parens--; continue; }
                if ($tokens[$j] === '{' || $tokens[$j] === ';') break;
                if ($tokens[$j] === ':' && $parens === 0) {
                    $k = $j + 1;
                    while ($k < $count && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) $k++;
                    $isInt = is_array($tokens[$k]) && strtolower($tokens[$k][1]) === 'int';
                    break;
                }
            }
            if (! $isInt) continue;

            $depth = 0;
            $started = false;
            $atStatementStart = true;
            $last = null;

            for ($j = $i; $j < $count; $j++) {
                $t = $tokens[$j];

                if (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;
                    continue;
                }
                if ($t === '{') { $depth++; $started = true; $atStatementStart = true; continue; }
                if ($t === '}') {
                    $depth--;
                    if ($started && $depth === 0) break;
                    if ($depth === 1) $atStatementStart = true;
                    continue;
                }
                if (! $started) continue;
                if ($depth === 1 && $t === ';') { $atStatementStart = true; continue; }
                if ($depth !== 1) continue;
                if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;

                if ($atStatementStart) {
                    $last = is_array($t) ? $t[0] : $t;
                    $atStatementStart = false;
                }
            }

            if (! in_array($last, [T_RETURN, T_THROW, T_EXIT], true)) {
                $found[] = $name . '()';
            }
        }

        return $found;
    }

    /**
     * The signatures a command answers to, read without building it.
     *
     * @return array<string, string>
     */
    private function signaturesOf(string $class): array
    {
        return defined($class . '::COMMANDS') ? constant($class . '::COMMANDS') : [];
    }

    /** Whether handle() chooses what to do based on the signature it was given. */
    private function dispatchesOnSignature(string $class): bool
    {
        $method = new ReflectionMethod($class, 'handle');
        $file = $method->getFileName();

        if ($file === false) {
            return false;
        }

        $lines = (array) file($file);
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $parameter = $method->getParameters()[0]->getName();

        return (bool) preg_match('/(match|switch)\s*\(\s*\$' . preg_quote($parameter, '/') . '\s*\)/', $body);
    }

    /** Build a command with stub dependencies, or null when that is not possible. */
    private function build(string $class): ?CommandInterface
    {
        $constructor = (new ReflectionClass($class))->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type === null || $type->isBuiltin()) {
                if (! $parameter->isDefaultValueAvailable()) {
                    return null;
                }
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            $name = (string) $type;

            if (! class_exists($name) && ! interface_exists($name)) {
                return null;
            }

            try {
                $arguments[] = interface_exists($name)
                    ? $this->createStub($name)
                    : (new ReflectionClass($name))->newInstanceWithoutConstructor();
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return new $class(...$arguments);
        } catch (\Throwable) {
            return null;
        }
    }
}
