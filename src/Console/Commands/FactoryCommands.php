<?php

namespace Nitro\Console\Commands;

use Nitro\Console\ExitCode;
use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\Contracts\PathRegistry;

/**
 * Generator commands for factories.
 *
 *   make:factory <Name> [--model=Foo]
 *     - <Name> is the factory class. Trailing "Factory" is optional;
 *       the command adds it if missing. PascalCase enforced.
 *     - --model lets you point at a model whose name differs from the
 *       factory base name (e.g. make:factory AdminFactory --model=User).
 *
 * Factories live in database/factories/ under Database\Factories\.
 * Use them via the model's HasFactory trait: User::factory()->create().
 */
class FactoryCommands implements CommandInterface
{
    private string $factoriesPath;

    public function __construct(
        private readonly OutputFormatter $output,
        PathRegistry $paths,
    ) {
        $this->factoriesPath = $paths->factories();
    }

    /**
     * Signature => description, as a constant so the manager can read it
     * without constructing the command.
     *
     * @var array<string, string>
     */
    public const COMMANDS = [
            'make:factory'  => 'Generate a new model factory',
            'factory:make'  => 'Invoke a factory from the CLI to create model row(s)',
        ];

    public function getCommands(): array
    {
        return self::COMMANDS;
    }

    public function handle(string $command, array $arguments = []): int
    {
        return match ($command) {
            'make:factory'  => $this->makeFactory($arguments),
            'factory:make'  => $this->invokeFactory($arguments),
            default         => $this->invalidSignature("Unknown factory command: {$command}"),
        };
    }

    private function makeFactory(array $arguments): int
    {
        // Positional name = first non-flag arg
        $name = null;
        $modelOverride = null;
        foreach ($arguments as $arg) {
            if (str_starts_with($arg, '--model=')) {
                $modelOverride = substr($arg, 8);
                continue;
            }
            if (str_starts_with($arg, '--')) continue;
            if ($name === null) $name = $arg;
        }

        if (!$name) {
            $this->output->error("Usage: make:factory <Name> [--model=ModelName]");
            $this->output->writeln("Example: make:factory UserFactory");
            $this->output->writeln("Example: make:factory AdminFactory --model=User");
            return ExitCode::SUCCESS;
        }

        $class = $this->normalizeClassName($name);
        if (!str_ends_with($class, 'Factory')) {
            $class .= 'Factory';
        }

        // Model name: explicit override OR strip "Factory" suffix.
        $model = $modelOverride ?? preg_replace('/Factory$/', '', $class);

        $path = $this->factoriesPath . '/' . $class . '.php';

        if (!is_dir($this->factoriesPath)) {
            mkdir($this->factoriesPath, 0775, true);
        }

        if (file_exists($path)) {
            $this->output->error("File already exists: {$class}.php");
            return ExitCode::FAILURE;
        }

        file_put_contents($path, $this->factoryStub($class, $model));
        $this->output->success("Created: database/factories/{$class}.php");
        $this->output->writeln("  Model assumed at: App\\Models\\{$model}");
        $this->output->writeln(
            "  Add `use HasFactory;` to App\\Models\\{$model} to enable {$model}::factory()."
        );

        return ExitCode::SUCCESS;
    }

    private function normalizeClassName(string $input): string
    {
        $input = preg_replace('/\.php$/', '', $input);
        $parts = preg_split('/[^a-zA-Z0-9]+/', $input);
        $parts = array_filter($parts);
        return implode('', array_map('ucfirst', $parts));
    }

    // ── factory:make ──────────────────────────────────────────────────

    /**
     * Invoke a factory directly from the CLI — the tinker-less way to
     * seed fake data on demand.
     *
     *   factory:make User
     *   factory:make User --count=10
     *   factory:make User --count=10 --state=admin --state=verified
     *   factory:make User --count=1 --override=status:banned --override=name:'Alice'
     *   factory:make User --count=5 --raw       (dump arrays, don't insert)
     *   factory:make User --count=5 --json      (insert + emit one JSON line per row)
     *
     * Model resolution:
     *   --model accepts a fully-qualified class name, OR
     *   the first positional arg is treated as a short name and resolved
     *   against App\Models\ (e.g. "User" → "App\Models\User").
     */
    private function invokeFactory(array $arguments): int
    {
        // Parse args/flags.
        $modelName = null;
        $count = 1;
        $states = [];
        $overrides = [];
        $raw  = false;
        $json = false;
        $fullClass = null;

        foreach ($arguments as $arg) {
            if (str_starts_with($arg, '--count=')) {
                $count = max(1, (int) substr($arg, 8));
                continue;
            }
            if (str_starts_with($arg, '--state=')) {
                $states[] = substr($arg, 8);
                continue;
            }
            if (str_starts_with($arg, '--override=')) {
                $overrides[] = substr($arg, 11);
                continue;
            }
            if (str_starts_with($arg, '--model=')) {
                $fullClass = substr($arg, 8);
                continue;
            }
            if ($arg === '--raw')   { $raw = true;  continue; }
            if ($arg === '--json')  { $json = true; continue; }
            if (str_starts_with($arg, '--')) {
                // Unknown flag — warn but keep going so a typo is loud.
                $this->output->warning("Unrecognised flag ignored: {$arg}");
                continue;
            }
            if ($modelName === null) {
                $modelName = $arg;
            }
        }

        if (!$modelName && !$fullClass) {
            $this->output->error("Usage: factory:make <Model> [--count=N] [--state=name] [--override=k:v] [--raw] [--json]");
            $this->output->writeln("Example: factory:make User --count=10 --state=admin");
            return ExitCode::FAILURE;
        }

        // Resolve the model class.
        $modelClass = $fullClass ?? ('App\\Models\\' . $modelName);
        if (!class_exists($modelClass)) {
            $this->output->error("Model class not found: {$modelClass}");
            $this->output->writeln("Pass --model=Fully\\Qualified\\ClassName if it lives outside App\\Models.");
            return ExitCode::FAILURE;
        }
        if (!method_exists($modelClass, 'factory')) {
            $this->output->error("{$modelClass} does not expose ::factory() — add `use HasFactory;` to the model.");
            return ExitCode::FAILURE;
        }

        // Build the factory chain: ::factory()->count(N)->state(...)->...
        try {
            $factory = $modelClass::factory($count);
        } catch (\Throwable $exception) {
            $this->output->error("Failed to construct factory: " . $exception->getMessage());
            return ExitCode::FAILURE;
        }

        foreach ($states as $stateName) {
            if (!method_exists($factory, $stateName)) {
                $this->output->error("Factory has no state method: '{$stateName}()' on " . $factory::class);
                return ExitCode::FAILURE;
            }
            $factory = $factory->{$stateName}();
        }

        $overrideMap = $this->parseOverrides($overrides);

        // Execute.
        if ($raw) {
            $data = $factory->raw($overrideMap);
            $rows = ($count === 1) ? [$data] : $data;
            $this->emitRows($rows, $json, persisted: false);
            return ExitCode::SUCCESS;
        }

        try {
            $created = $factory->create($overrideMap);
        } catch (\Throwable $exception) {
            $this->output->error("Factory ->create() failed: " . $exception->getMessage());
            $this->output->writeln("(Check that the table exists, columns match, and constraints aren't violated.)");
            return ExitCode::FAILURE;
        }

        $rows = is_array($created) ? $created : [$created];
        $this->emitRows($rows, $json, persisted: true);
        $this->output->success(sprintf("%s %d %s.",
            $raw ? "Rendered" : "Created",
            count($rows),
            $modelName ?? $modelClass,
        ));

        return ExitCode::SUCCESS;
    }

    /**
     * Parse `--override=key:value` flags into a flat ['key' => 'value'] map.
     *
     * Splits on the FIRST colon only — values may contain colons (URLs,
     * timestamps, etc.). Boolean-looking values are coerced ('true'/'false'/
     * '1'/'0') because CLI shells lose type info on the way in.
     */
    private function parseOverrides(array $raw): array
    {
        $out = [];
        foreach ($raw as $entry) {
            $pos = strpos($entry, ':');
            if ($pos === false) {
                $this->output->warning("Override missing ':' separator, ignored: {$entry}");
                continue;
            }
            $key = substr($entry, 0, $pos);
            $val = substr($entry, $pos + 1);

            // Coerce common types so --override=is_admin:true doesn't end
            // up as the string "true" in the database.
            $coerced = match (strtolower($val)) {
                'true'  => true,
                'false' => false,
                'null'  => null,
                default => is_numeric($val) ? (str_contains($val, '.') ? (float) $val : (int) $val) : $val,
            };
            $out[$key] = $coerced;
        }
        return $out;
    }

    /** Render one or many model/array rows as text or JSON lines. */
    private function emitRows(array $rows, bool $json, bool $persisted): void
    {
        foreach ($rows as $i => $row) {
            $attrs = is_array($row) ? $row : (method_exists($row, 'toArray') ? $row->toArray() : (array) $row);

            if ($json) {
                $this->output->writeln(json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                continue;
            }

            $id = $attrs['id'] ?? '-';
            $summary = $persisted ? "Created #{$id}" : "Rendered row " . ($i + 1);
            $preview = $this->previewLine($attrs);
            $this->output->writeln("  {$summary}: {$preview}");
        }
    }

    /** Compact "key=value key=value …" preview, truncated for readability. */
    private function previewLine(array $attrs, int $maxKeys = 4): string
    {
        $kept = array_slice($attrs, 0, $maxKeys, true);
        $parts = [];
        foreach ($kept as $attribute => $value) {
            $formatted = is_scalar($value) ? (string) $value : json_encode($value);
            if (strlen($formatted) > 40) $formatted = substr($formatted, 0, 37) . '…';
            $parts[] = "{$attribute}={$formatted}";
        }
        return implode(' ', $parts);
    }

    private function factoryStub(string $class, string $model): string
    {
        return <<<PHP
        <?php

        namespace Database\\Factories;

        use App\\Models\\{$model};
        use Nitro\\Database\\Factory\\Factory;

        class {$class} extends Factory
        {
            protected string \$model = {$model}::class;

            public function definition(): array
            {
                return [
                    'name'  => \$this->faker->name(),
                    'email' => \$this->faker->unique()->email(),
                ];
            }

            // Example state — call as: {$model}::factory()->admin()->create()
            //
            // public function admin(): self
            // {
            //     return \$this->state(['is_admin' => true]);
            // }
        }

        PHP;
    }
    /** Report an unrecognised signature and fail the invocation. */
    private function invalidSignature(string $message): int
    {
        $this->output->error($message);

        return ExitCode::INVALID;
    }
}
