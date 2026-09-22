<?php

namespace Nitro\Console;

use Nitro\Foundation\Contracts\PathRegistry;
use Nitro\Support\Str;

/**
 * Base class for a command that writes a class file from a stub.
 *
 * A generator declares where its output belongs and what it should contain;
 * everything else — turning "Billing/Invoice" into a namespace, a class name
 * and a path, creating the directory, refusing to overwrite — is here.
 *
 *   class MakeServiceCommand extends GeneratorCommand
 *   {
 *       protected string $signature = 'make:service {name} {--force}';
 *       protected string $description = 'Create a service class';
 *
 *       protected function namespace(): string { return 'App\Services'; }
 *       protected function suffix(): string    { return 'Service'; }
 *
 *       protected function stub(): string
 *       {
 *           return <<<'PHP'
 *               <?php
 *
 *               namespace {{ namespace }};
 *
 *               class {{ class }}
 *               {
 *               }
 *               PHP;
 *       }
 *   }
 *
 * `make:service billing/invoice` then writes app/Services/Billing/InvoiceService.php.
 */
abstract class GeneratorCommand extends Command
{
    public function __construct(protected PathRegistry $paths) {}

    /** The namespace the generated class belongs to, e.g. 'App\Services'. */
    abstract protected function namespace(): string;

    /** The file's contents, with {{ namespace }} and {{ class }} to fill in. */
    abstract protected function stub(): string;

    /**
     * Appended to the class name when the given name does not already end with
     * it, so `make:service invoice` and `make:service InvoiceService` produce
     * the same class.
     */
    protected function suffix(): string
    {
        return '';
    }

    /** What this generator makes, for the message it prints. */
    protected function type(): string
    {
        return 'Class';
    }

    /**
     * Values substituted into the stub.
     *
     * Override to add your own; the namespace and class are always present.
     *
     * @return array<string, string>
     */
    protected function replacements(string $namespace, string $class): array
    {
        return [];
    }

    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if ($name === '') {
            $this->error('A name is required.');

            return ExitCode::FAILURE;
        }

        [$namespace, $class, $relative] = $this->resolveTarget($name);

        // Checked rather than assumed: a name that cannot be a class produces a
        // file PHP cannot parse, and the error surfaces at the next autoload
        // rather than here where it can be explained.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class) !== 1) {
            $this->error("[{$class}] is not a valid class name.");

            return ExitCode::FAILURE;
        }

        $path = $this->paths->base($relative);

        if (is_file($path) && ! $this->option('force')) {
            $this->error("{$this->type()} already exists: {$relative}");

            return ExitCode::FAILURE;
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error("Could not create directory: " . dirname($relative));

            return ExitCode::FAILURE;
        }

        if (file_put_contents($path, $this->render($namespace, $class)) === false) {
            $this->error("Could not write: {$relative}");

            return ExitCode::FAILURE;
        }

        $this->components->success("{$this->type()} created: {$relative}");

        return ExitCode::SUCCESS;
    }

    /**
     * Split a given name into its namespace, class and path.
     *
     * Accepts either separator, so `Billing\Invoice` and `billing/invoice`
     * both work — one is what a Windows shell escapes badly and the other is
     * what most people type.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    protected function resolveTarget(string $name): array
    {
        $name = str_replace('\\', '/', trim($name, '/\\'));

        /*
         * Studly rather than ucfirst: `monthly-report` has to become
         * MonthlyReport, since a hyphen or an underscore left in place makes a
         * class name PHP cannot parse — and the file is written before anyone
         * finds out.
         */
        $segments = array_map(
            static fn (string $segment): string => Str::studly($segment),
            array_filter(explode('/', $name), static fn (string $s): bool => $s !== '')
        );

        $class = array_pop($segments) ?? '';

        if ($this->suffix() !== '' && ! str_ends_with($class, $this->suffix())) {
            $class .= $this->suffix();
        }

        $namespace = $this->namespace() . ($segments !== [] ? '\\' . implode('\\', $segments) : '');

        return [$namespace, $class, $this->directory($segments) . $class . '.php'];
    }

    /**
     * Where the file goes, derived from the namespace.
     *
     * `App\Services` becomes `app/Services`, following the PSR-4 root the
     * application's composer.json declares. Override for a generator whose
     * output does not live under the application namespace.
     *
     * @param array<int, string> $segments
     */
    protected function directory(array $segments): string
    {
        $base = str_replace('\\', '/', $this->namespace());
        $base = preg_replace('#^App(/|$)#', 'app$1', $base) ?? $base;

        return rtrim($base, '/') . '/' . ($segments !== [] ? implode('/', $segments) . '/' : '');
    }

    /** The stub with its placeholders filled in. */
    protected function render(string $namespace, string $class): string
    {
        $values = ['namespace' => $namespace, 'class' => $class]
            + $this->replacements($namespace, $class);

        $stub = $this->stub();

        foreach ($values as $key => $value) {
            $stub = str_replace(['{{ ' . $key . ' }}', '{{' . $key . '}}'], $value, $stub);
        }

        return $stub;
    }
}
