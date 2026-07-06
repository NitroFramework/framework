<?php

namespace Nitro\Console\Commands;

use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Database\Seeder\Seeder;
use Nitro\Foundation\PathRegistry;

/**
 * Seeder commands.
 *
 *   make:seeder <Name>            Generate a Seeder stub.
 *   db:seed [--class=ClassName]   Run the root DatabaseSeeder, or a
 *                                 named class.
 *
 * Seeders live in `database/seeders/` under the `Database\Seeders\`
 * namespace by default. The root file `DatabaseSeeder.php` is the
 * entry point — it typically just `$this->call([...])`s a list of
 * child seeders so callers don't have to know the full inventory.
 */
class SeederCommands implements CommandInterface
{
    private string $seedersPath;
    private string $seedersNamespace = 'Database\\Seeders\\';

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly OutputFormatter $output,
        PathRegistry $paths,
    ) {
        $this->seedersPath = $paths->seeders();
    }

    public function getCommands(): array
    {
        return [
            'make:seeder' => 'Generate a new seeder class',
            'db:seed'     => 'Run database seeders (defaults to DatabaseSeeder)',
        ];
    }

    public function handle(string $command, array $arguments = []): void
    {
        match ($command) {
            'make:seeder' => $this->makeSeeder($arguments),
            'db:seed'     => $this->dbSeed($arguments),
            default       => $this->output->error("Unknown seeder command: {$command}"),
        };
    }

    // ── make:seeder ───────────────────────────────────────────────────

    private function makeSeeder(array $arguments): void
    {
        $name = $arguments[0] ?? null;
        if (!$name) {
            $this->output->error("Usage: make:seeder <Name>");
            $this->output->writeln("Example: make:seeder UsersSeeder");
            return;
        }

        // Normalize: PascalCase the class name, append Seeder if missing.
        $class = $this->normalizeClassName($name);
        if (!str_ends_with($class, 'Seeder')) {
            $class .= 'Seeder';
        }

        $path = $this->seedersPath . '/' . $class . '.php';

        if (!is_dir($this->seedersPath)) {
            mkdir($this->seedersPath, 0775, true);
        }

        if (file_exists($path)) {
            $this->output->error("File already exists: {$class}.php");
            return;
        }

        file_put_contents($path, $this->seederStub($class));
        $this->output->success("Created: database/seeders/{$class}.php");
    }

    // ── db:seed ───────────────────────────────────────────────────────

    private function dbSeed(array $arguments): void
    {
        $classFlag = $this->flagValue($arguments, '--class');
        $class = $classFlag ?? 'DatabaseSeeder';

        // Bare name → resolve against the default seeder namespace.
        // Fully-qualified name → use as-is.
        $fqcn = str_contains($class, '\\')
            ? ltrim($class, '\\')
            : $this->seedersNamespace . $class;

        // Pull the file in case the autoloader doesn't know about it
        // (default app skeleton may not have set up PSR-4 for Database\Seeders).
        $this->autoloadSeeders();

        if (!class_exists($fqcn)) {
            $this->output->error("Seeder class not found: {$fqcn}");
            $this->output->writeln(
                "Looked in " . $this->seedersPath . " — "
                . "make sure the file exists and the namespace is `Database\\Seeders`."
            );
            return;
        }
        if (!is_subclass_of($fqcn, Seeder::class)) {
            $this->output->error("{$fqcn} does not extend " . Seeder::class);
            return;
        }

        $this->output->info("Seeding: {$fqcn}");
        $seeder = $this->container->make($fqcn);
        $seeder->run();
        $this->output->success("Database seeding completed.");
    }

    // ── helpers ───────────────────────────────────────────────────────

    /**
     * Require every PHP file under database/seeders so classes are
     * available even without PSR-4 wiring. Cheap, idempotent.
     */
    private function autoloadSeeders(): void
    {
        if (!is_dir($this->seedersPath)) return;
        foreach (glob($this->seedersPath . '/*.php') as $file) {
            require_once $file;
        }
    }

    private function normalizeClassName(string $input): string
    {
        // Strip extension if user typed UsersSeeder.php
        $input = preg_replace('/\.php$/', '', $input);
        // Split on non-alphanumeric, PascalCase each token, rejoin.
        $parts = preg_split('/[^a-zA-Z0-9]+/', $input);
        $parts = array_filter($parts);
        return implode('', array_map('ucfirst', $parts));
    }

    private function flagValue(array $args, string $flag): ?string
    {
        foreach ($args as $a) {
            if (str_starts_with($a, $flag . '=')) {
                return substr($a, strlen($flag) + 1);
            }
        }
        return null;
    }

    private function seederStub(string $class): string
    {
        return <<<PHP
        <?php

        namespace Database\\Seeders;

        use Nitro\\Database\\DB;
        use Nitro\\Database\\Seeder\\Seeder;

        class {$class} extends Seeder
        {
            public function run(): void
            {
                // DB::table('users')->insert([
                //     'name' => 'Admin',
                //     'email' => 'admin@example.com',
                // ]);
            }
        }

        PHP;
    }
}
