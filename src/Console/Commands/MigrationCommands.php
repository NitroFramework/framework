<?php

namespace Nitro\Console\Commands;

use Nitro\Console\Concerns\ConfirmsInProduction;
use Nitro\Console\ExitCode;
use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Database\DB;
use Nitro\Database\Migration\MigrationPathRegistry;
use Nitro\Database\Schema\SchemaBuilder;
use Nitro\Database\Schema\SchemaCache;
use Nitro\Database\SQLiteDatabaseDoesNotExistException;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\Contracts\PathRegistry;
use RuntimeException;

/**
 * Migration command bundle, Laravel-ish surface.
 *
 *   make:migration <name>             Generate a new timestamped migration file.
 *   migrate:install                   Create the tracking table without running anything.
 *   migrate:run [--step] [--force]    Run pending migrations.
 *   migrate:rollback [--step=N] [--force]
 *                                     Roll back the last batch (or N batches).
 *   migrate:reset [--force]           Roll back EVERY applied migration.
 *   migrate:refresh [--force]         reset + run (recreates schema; preserves nothing).
 *   migrate:fresh [--force]           Drop every table, then run.
 *   migrate:status                    Show what's ran (with batch) vs pending.
 *   migrate:mark-ran <name|--all>     Record migrations as applied WITHOUT running.
 *                                     Used when adopting an existing database whose
 *                                     tables already exist but whose tracking rows
 *                                     are missing.
 *
 * Production-safety: destructive commands (rollback / reset / refresh / fresh)
 * require --force when APP_ENV=production. This is a deliberate "are you sure?"
 * because forgetting --force in prod is the entire point of the guard.
 *
 * --step semantics: by default migrate:run records every applied file under
 * the same batch number, so rollback undoes them all together. With --step,
 * each migration gets its OWN batch number — rollback then unwinds one
 * migration at a time. Use --step when you want fine-grained rollback control.
 */
class MigrationCommands implements CommandInterface
{
    use ConfirmsInProduction;

    private string $migrationsPath;
    private string $migrationsTable = 'migrations';

    public function __construct(
        private readonly SchemaBuilder $schema,
        private readonly ConfigRepository $config,
        private readonly OutputFormatter $output,
        private readonly SeederCommands $seeder,
        private readonly MigrationPathRegistry $migrationPaths,
        PathRegistry $paths
    ) {
        // The application's own migrations directory — new migrations are created
        // here. Discovery/execution scans every path in $migrationPaths (this dir
        // plus any registered by modules).
        $this->migrationsPath = $paths->migrations();
    }

    /**
     * Signature => description, as a constant so the manager can read it
     * without constructing the command.
     *
     * @var array<string, string>
     */
    public const COMMANDS = [
            'make:migration'    => 'Generate a new migration file',
            // 'migrate' is the name everybody reaches for first, and getting
            // "Command 'migrate' not found" on a fresh install reads as a
            // broken install rather than as a naming convention.
            'migrate'           => 'Run all pending migrations (alias of migrate:run)',
            'migrate:install'   => 'Create the migrations tracking table',
            'migrate:run'       => 'Run all pending migrations',
            'migrate:rollback'  => 'Rollback the last batch (or N batches via --step=N)',
            'migrate:reset'     => 'Rollback ALL migrations',
            'migrate:refresh'   => 'Rollback all migrations then re-run them',
            'migrate:fresh'     => 'Drop all tables and re-run all migrations',
            'migrate:status'    => 'Show the status of each migration',
            'migrate:mark-ran'  => 'Record migration(s) as ran without executing them',
        ];

    /**
     * The commands that may create a missing SQLite file: those whose point is
     * to build the schema. A read like migrate:status reports it missing.
     *
     * @var array<int, string>
     */
    private const CREATES_DATABASE = ['migrate', 'migrate:run', 'migrate:install', 'migrate:fresh', 'migrate:refresh'];

    public function getCommands(): array
    {
        return self::COMMANDS;
    }

    /**
     * Offer to create the SQLite file the configuration names.
     *
     * With --force it is created without asking. With --no-interaction, or no
     * terminal to ask at, it is not, and the missing file is reported. Declining
     * aborts the migration.
     *
     * @param array<int, string> $arguments
     * @return bool Whether the file now exists.
     */
    private function createMissingSqliteDatabase(string $path, array $arguments): bool
    {
        if ($this->flag($arguments, '--force')) {
            return touch($path);
        }

        if ($this->flag($arguments, '--no-interaction') || ! $this->canAsk()) {
            return false;
        }

        $this->output->warning('The SQLite database configured for this application does not exist: ' . $path);

        fwrite(STDOUT, ' Would you like to create it? (yes/no) [yes]: ');

        $answer = strtolower(trim((string) fgets(STDIN)));

        if ($answer !== '' && ! in_array($answer, ['y', 'yes'], true)) {
            $this->output->info('Operation cancelled. No database was created.');

            throw new RuntimeException('Database was not created. Aborting migration.');
        }

        return touch($path);
    }

    /** Whether there is a terminal on STDIN to answer a question. */
    private function canAsk(): bool
    {
        if (! defined('STDIN')) {
            return false;
        }

        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDIN);
        }

        return function_exists('posix_isatty') && @posix_isatty(STDIN);
    }

    public function handle(string $command, array $arguments = []): int
    {
        // make:migration runs against the filesystem only — no DB needed.
        if ($command === 'make:migration') {
            $this->makeMigration($arguments);
            return ExitCode::SUCCESS;
        }

        // Checked before anything touches the database: a signature this class
        // does not answer to should say so, not open a connection and create a
        // migrations table on the way to finding out.
        if (! array_key_exists($command, self::COMMANDS)) {
            return $this->invalidSignature("Unknown migration command: {$command}");
        }

        // Migrations mutate the schema, so the optimize-time schema cache must
        // not be trusted here: read live during the command (so e.g. the
        // migrations-table check sees reality, not a stale cache), and drop the
        // cached file afterwards so the next process boots with fresh schema.
        SchemaCache::bypass(true);

        try {
            try {
                $this->ensureMigrationsTableExists();
            } catch (SQLiteDatabaseDoesNotExistException $missing) {
                if (! in_array($command, self::CREATES_DATABASE, true)
                    || ! $this->createMissingSqliteDatabase($missing->path, $arguments)) {
                    throw $missing;
                }

                $this->ensureMigrationsTableExists();
            }

            $code = match ($command) {
                'migrate:install'   => $this->reportMigrationsTableReady(),
                'migrate', 'migrate:run' => $this->runMigrations($arguments),
                'migrate:rollback'  => $this->rollbackMigrations($arguments),
                'migrate:reset'     => $this->resetMigrations($arguments),
                'migrate:refresh'   => $this->refreshMigrations($arguments),
                'migrate:fresh'     => $this->freshMigrations($arguments),
                'migrate:status'    => $this->showStatus(),
                'migrate:mark-ran'  => $this->markRan($arguments),
                default             => $this->invalidSignature("Unknown migration command: {$command}")
            };
        } finally {
            // Invalidate the on-disk schema cache after any command that could
            // have changed the schema (even a partial/failed run). status is
            // read-only, so it leaves the cache untouched.
            if ($command !== 'migrate:status') {
                SchemaCache::clear();
            }
        }

        return $code;
    }

    /**
     * migrate:install has nothing left to do: ensureMigrationsTableExists()
     * has already created the table if it was missing.
     */
    private function reportMigrationsTableReady(): int
    {
        $this->output->success('Migrations table is ready.');

        return ExitCode::SUCCESS;
    }

    // ── make:migration ────────────────────────────────────────────────

    private function makeMigration(array $arguments): int
    {
        $name = $arguments[0] ?? null;
        if (!$name) {
            $this->output->error("Usage: make:migration <name>");
            $this->output->writeln("Example: make:migration create_orders_table");
            return ExitCode::FAILURE;
        }

        // snake_case the name: split CamelCase first (CreateOrdersTable →
        // Create_Orders_Table), then lowercase, then collapse runs of
        // non-alphanumerics into single underscores. Matches Laravel's
        // shape for either naming style.
        $withBreaks = preg_replace('/(?<!^)([A-Z])/', '_$1', $name);
        $snake = preg_replace('/[^a-zA-Z0-9]+/', '_', strtolower($withBreaks));
        $snake = trim($snake, '_');
        if ($snake === '') {
            $this->output->error("Migration name must contain at least one alphanumeric char.");
            return ExitCode::FAILURE;
        }

        $timestamp = date('Y_m_d_His');
        $filename  = "{$timestamp}_{$snake}.php";
        $path      = $this->migrationsPath . '/' . $filename;

        if (!is_dir($this->migrationsPath)) {
            mkdir($this->migrationsPath, 0775, true);
        }

        if (file_exists($path)) {
            $this->output->error("File already exists: {$filename}");
            return ExitCode::FAILURE;
        }

        // Guess the table from the migration name so the stub is useful out of
        // the box ("create_orders_table" → "orders"). The name also says which
        // stub: creating a table and adding a column to one are not the same
        // migration. When it implies neither, emit a blank one rather than
        // scaffolding a create() the developer has to notice and delete.
        $guess = $this->guessTable($snake);

        file_put_contents($path, match (true) {
            $guess === null => $this->blankMigrationStub(),
            $guess['create'] => $this->createMigrationStub($guess['table']),
            default => $this->updateMigrationStub($guess['table']),
        });
        $this->output->success("Created: database/migrations/{$filename}");

        return ExitCode::SUCCESS;
    }

    /**
     * The table a migration targets and whether it creates it.
     *
     * 'create_orders_table' makes a table; 'add_status_to_orders_table'
     * changes one. A name that says neither returns null.
     *
     * @return array{table: string, create: bool}|null
     */
    private function guessTable(string $snake): ?array
    {
        foreach (['/^create_(\w+)_table$/', '/^create_(\w+)$/'] as $pattern) {
            if (preg_match($pattern, $snake, $matches)) {
                return ['table' => $matches[1], 'create' => true];
            }
        }

        foreach (['/.+_(?:to|from|in)_(\w+)_table$/', '/.+_(?:to|from|in)_(\w+)$/'] as $pattern) {
            if (preg_match($pattern, $snake, $matches)) {
                return ['table' => $matches[1], 'create' => false];
            }
        }

        return null;
    }

    /** A migration that makes a table. */
    private function createMigrationStub(string $table): string
    {
        return <<<PHP
        <?php

        use Nitro\\Database\\Schema\\Blueprint;
        use Nitro\\Facades\\Schema;

        return new class {
            public function up(): void
            {
                Schema::create('{$table}', function (Blueprint \$table) {
                    \$table->id();
                    // \$table->string('name');
                    \$table->timestamps();
                });
            }

            public function down(): void
            {
                Schema::dropIfExists('{$table}');
            }
        };

        PHP;
    }

    /** A migration that changes a table that already exists. */
    private function updateMigrationStub(string $table): string
    {
        return <<<PHP
        <?php

        use Nitro\\Database\\Schema\\Blueprint;
        use Nitro\\Facades\\Schema;

        return new class {
            public function up(): void
            {
                Schema::table('{$table}', function (Blueprint \$table) {
                    //
                });
            }

            public function down(): void
            {
                Schema::table('{$table}', function (Blueprint \$table) {
                    //
                });
            }
        };

        PHP;
    }

    /**
     * A blank migration — empty up()/down().
     *
     * Used when the name implies no table, so the developer states the intent
     * instead of noticing and deleting a wrong-guessed one.
     */
    private function blankMigrationStub(): string
    {
        return <<<PHP
        <?php

        use Nitro\\Database\\Schema\\Blueprint;
        use Nitro\\Facades\\Schema;

        return new class {
            public function up(): void
            {
                //
            }

            public function down(): void
            {
                //
            }
        };

        PHP;
    }

    // ── migrate:run ───────────────────────────────────────────────────

    private function runMigrations(array $arguments): int
    {
        $step    = $this->flag($arguments, '--step');
        $force   = $this->flag($arguments, '--force');
        $pretend = $this->flag($arguments, '--pretend');

        $this->output->info($pretend
            ? "Running migrations (--pretend, no changes will be applied)...\n"
            : "Running migrations...\n");

        $files   = $this->getMigrationFiles();
        $ran     = $this->getRanMigrations();
        $pending = array_values(array_diff($files, $ran));

        if (empty($pending)) {
            $this->output->success("Nothing to migrate!");
            return ExitCode::SUCCESS;
        }

        $batch = $this->getNextBatchNumber();

        foreach ($pending as $file) {
            $this->runMigration($file, $batch, $pretend);
            // With --step, every migration gets its own batch number so
            // rollback unwinds them one at a time. Without it, the whole
            // run shares one batch and rollback undoes the lot in one go.
            if ($step) {
                $batch++;
            }
        }

        $this->output->success($pretend
            ? "\n--pretend complete (no rows written to the migrations table)."
            : "\nMigrations completed successfully!");

        return ExitCode::SUCCESS;
    }

    private function runMigration(string $file, int $batch, bool $pretend = false): void
    {
        $path = $this->resolveMigrationPath($file);

        if ($path === null) {
            $this->output->error("Migration file not found: {$file}");
            return;
        }

        $this->output->write(($pretend ? "[pretend] " : "") . "Migrating: {$file}...");

        try {
            $migration = require $path;

            if ($pretend) {
                $log = DB::connection()->pretending(function () use ($migration) {
                    $this->invokeMigration($migration, 'up');
                });
                $this->output->writeln(" (would execute " . count($log) . " statements)");
                $this->dumpPretendLog($log);
            } else {
                $this->invokeMigration($migration, 'up');
                $this->recordMigration($file, $batch);
                $this->output->success(" DONE");
            }
        } catch (\Throwable $exception) {
            $this->output->error(" FAILED");
            $this->output->error("Error: " . $exception->getMessage());
            throw $exception;
        }
    }

    /**
     * Run up() or down() on a migration, passing the schema builder only when
     * the method asks for it.
     *
     * Two shapes are supported: a plain anonymous class declaring
     * up(SchemaBuilder $schema), and one extending Migration declaring up()
     * with no parameters and resolving the builder through the Schema facade.
     */
    private function invokeMigration(object $migration, string $method): void
    {
        if (! method_exists($migration, $method)) {
            return;
        }

        $wantsSchema = (new \ReflectionMethod($migration, $method))->getNumberOfParameters() > 0;

        $wantsSchema
            ? $migration->{$method}($this->schema)
            : $migration->{$method}();
    }

    /** Print captured SQL from a pretend pass, one statement per block. */
    private function dumpPretendLog(array $log): void
    {
        foreach ($log as $i => $row) {
            $this->output->writeln("  --- statement #" . ($i + 1) . " ---");
            // Indent every line so the SQL blocks stand apart from
            // command-level status messages.
            foreach (explode("\n", trim($row['sql'])) as $line) {
                $this->output->writeln("  " . $line);
            }
            if (!empty($row['bindings'])) {
                $this->output->writeln("  bindings: " . json_encode($row['bindings']));
            }
        }
    }

    // ── migrate:rollback [--step=N] ───────────────────────────────────

    private function rollbackMigrations(array $arguments): int
    {
        if (!$this->confirmDestructive('rollback', $arguments)) return ExitCode::SUCCESS;

        $step    = $this->flagValue($arguments, '--step');
        $pretend = $this->flag($arguments, '--pretend');

        $this->output->info($pretend
            ? "Rolling back (--pretend)...\n"
            : "Rolling back migrations...\n");

        // Without --step the last batch comes off, which is what one migrate
        // put on. With it, that many migrations come off regardless of the
        // batches they went on in — counting batches here would undo far more
        // than the number asked for.
        $toRollBack = $step === null
            ? $this->getLastBatchDescending()
            : $this->getLastMigrations(max(1, (int) $step));

        if ($toRollBack === []) {
            $this->output->success("Nothing to rollback!");
            return ExitCode::SUCCESS;
        }

        foreach ($toRollBack as $migration) {
            $this->rollbackMigration($migration, $pretend);
        }

        $this->output->success($pretend
            ? "\n--pretend complete (no changes applied)."
            : "\nRollback completed successfully!");

        return ExitCode::SUCCESS;
    }

    private function rollbackMigration(string $file, bool $pretend = false): void
    {
        $path = $this->resolveMigrationPath($file);
        if ($path === null) {
            $this->output->error("Migration file not found: {$file}");
            return;
        }
        $this->output->write(($pretend ? "[pretend] " : "") . "Rolling back: {$file}...");

        try {
            $migration = require $path;

            if ($pretend) {
                $log = DB::connection()->pretending(function () use ($migration) {
                    $this->invokeMigration($migration, 'down');
                });
                $this->output->writeln(" (would execute " . count($log) . " statements)");
                $this->dumpPretendLog($log);
            } else {
                $this->invokeMigration($migration, 'down');
                $this->removeMigration($file);
                $this->output->success(" DONE");
            }
        } catch (\Throwable $exception) {
            $this->output->error(" FAILED");
            $this->output->error("Error: " . $exception->getMessage());
            throw $exception;
        }
    }

    // ── migrate:reset ─────────────────────────────────────────────────

    private function resetMigrations(array $arguments): int
    {
        if (!$this->confirmDestructive('reset', $arguments)) return ExitCode::SUCCESS;

        $this->output->info("Resetting all migrations...\n");

        $batches = $this->getBatchesDescending();
        if (empty($batches)) {
            $this->output->success("Nothing to reset!");
            return ExitCode::SUCCESS;
        }

        foreach ($batches as $batch) {
            foreach (array_reverse($this->getMigrationsFromBatch($batch)) as $migration) {
                $this->rollbackMigration($migration);
            }
        }

        $this->output->success("\nReset completed successfully!");

        return ExitCode::SUCCESS;
    }

    // ── migrate:refresh ───────────────────────────────────────────────

    private function refreshMigrations(array $arguments): int
    {
        if (!$this->confirmDestructive('refresh', $arguments)) return ExitCode::SUCCESS;

        // Pass --force through to the underlying reset/run so we don't
        // prompt twice in the same operation.
        $args = array_unique(array_merge($arguments, ['--force']));

        $this->resetMigrations($args);
        $this->runMigrations($args);

        if ($this->flag($arguments, '--seed')) {
            $this->seedAfterMigrations($arguments);
        }

        return ExitCode::SUCCESS;
    }

    // ── migrate:fresh ─────────────────────────────────────────────────

    private function freshMigrations(array $arguments): int
    {
        if (!$this->confirmDestructive('fresh', $arguments)) return ExitCode::SUCCESS;

        $this->output->info("Dropping all tables...");

        // Drop with foreign-key checks disabled so tables come down regardless of
        // FK dependency order. Without this, dropping a parent table that a
        // child's FK still references fails mid-loop and leaves the schema
        // half-dropped (e.g. attendances gone, categories un-droppable because
        // posts references it). Restored in the finally so the connection isn't
        // left with checks off for the migrate run that follows.
        $this->setForeignKeyChecks(false);
        try {
            foreach ($this->getAllTables() as $table) {
                // table names come back as objects via information_schema
                $name = is_object($table) ? ($table->table_name ?? $table->TABLE_NAME ?? null) : $table;
                if (!$name || $name === $this->migrationsTable) continue;
                $this->schema->dropIfExists($name);
                $this->output->write("Dropped: {$name}\n");
            }
        } finally {
            $this->setForeignKeyChecks(true);
        }

        DB::table($this->migrationsTable)->delete();
        $this->output->success("All tables dropped!\n");

        // Run with --force so we don't re-prompt in the run path.
        $this->runMigrations(array_merge($arguments, ['--force']));

        if ($this->flag($arguments, '--seed')) {
            $this->seedAfterMigrations($arguments);
        }

        return ExitCode::SUCCESS;
    }

    /**
     * After a fresh/refresh, run the root DatabaseSeeder (or a named
     * class via --seeder=). Delegates to the SeederCommands so any
     * change in seeder semantics stays in one place.
     */
    private function seedAfterMigrations(array $arguments): void
    {
        $seederClass = $this->flagValue($arguments, '--seeder') ?? 'DatabaseSeeder';

        $this->output->info("\nSeeding database...");
        try {
            $this->seeder->handle('db:seed', ['--class=' . $seederClass]);
        } catch (\Throwable $exception) {
            $this->output->error("Seeding failed: " . $exception->getMessage());
            throw $exception;
        }
    }

    // ── migrate:status ────────────────────────────────────────────────

    private function showStatus(): int
    {
        $files = $this->getMigrationFiles();
        $ranBatchMap = $this->getRanWithBatches();

        if (empty($files)) {
            $this->output->info("No migration files found.");
            return ExitCode::SUCCESS;
        }

        $this->output->info("Migration Status:\n");
        $this->output->writeln(str_repeat('-', 90));
        $this->output->writeln(sprintf("%-50s %-12s %s", "Migration", "Status", "Batch"));
        $this->output->writeln(str_repeat('-', 90));

        $ranCount = 0;
        $pendingCount = 0;
        foreach ($files as $file) {
            if (isset($ranBatchMap[$file])) {
                $statusFmt = "\033[32mRan\033[0m";
                $batch     = (string) $ranBatchMap[$file];
                $ranCount++;
            } else {
                $statusFmt = "\033[33mPending\033[0m";
                $batch     = "-";
                $pendingCount++;
            }
            $this->output->writeln(sprintf("%-50s %-21s %s", $file, $statusFmt, $batch));
        }

        $this->output->writeln(str_repeat('-', 90));
        $this->output->writeln(sprintf("Ran: %d   Pending: %d   Total: %d",
            $ranCount, $pendingCount, $ranCount + $pendingCount));

        return ExitCode::SUCCESS;
    }

    // ── migrate:mark-ran ──────────────────────────────────────────────

    private function markRan(array $arguments): int
    {
        $files = $this->getMigrationFiles();
        $ran   = $this->getRanMigrations();
        $pending = array_values(array_diff($files, $ran));

        $all = $this->flag($arguments, '--all');
        $named = array_values(array_filter(
            $arguments,
            fn($argument) => !str_starts_with($argument, '--')
        ));

        if (!$all && empty($named)) {
            $this->output->error("Usage: migrate:mark-ran <name> [<name> …]");
            $this->output->writeln("       migrate:mark-ran --all      (marks every pending migration as ran)");
            return ExitCode::FAILURE;
        }

        $targets = $all ? $pending : $named;

        // Validate every requested name actually exists as a migration file
        // — otherwise a typo silently records a nonexistent file as "ran"
        // and the corresponding real migration eventually runs anyway.
        foreach ($targets as $name) {
            if (!in_array($name, $files, true)) {
                $this->output->error("Migration file not found: {$name}");
                return ExitCode::FAILURE;
            }
            if (in_array($name, $ran, true)) {
                $this->output->warning("Already marked as ran: {$name}");
            }
        }

        $batch = $this->getNextBatchNumber();
        $marked = 0;
        foreach ($targets as $name) {
            if (in_array($name, $ran, true)) continue;
            $this->recordMigration($name, $batch);
            $this->output->writeln("Marked: {$name}");
            $marked++;
        }

        $this->output->success("Marked {$marked} migration(s) as ran (batch {$batch}).");

        return ExitCode::SUCCESS;
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function getMigrationFiles(): array
    {
        $files = [];

        foreach ($this->migrationPaths->all() as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*.php') ?: [] as $file) {
                // Keyed by basename so the same migration is never listed twice;
                // ordering is by name below (filenames are timestamp-prefixed,
                // so this is chronological across the app and all modules).
                $files[basename($file)] = basename($file);
            }
        }

        $names = array_values($files);
        sort($names);
        return $names;
    }

    /**
     * Resolve a migration file's absolute path by basename, searching the
     * application migrations directory and every registered module path.
     */
    private function resolveMigrationPath(string $file): ?string
    {
        foreach ($this->migrationPaths->all() as $dir) {
            $candidate = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function getRanMigrations(): array
    {
        return DB::table($this->migrationsTable)
            ->orderBy('migration', 'asc')
            ->pluck('migration');
    }

    /** Map of migration file → batch number for everything that's ran. */
    private function getRanWithBatches(): array
    {
        $rows = DB::table($this->migrationsTable)
            ->orderBy('migration', 'asc')
            ->get()
            ->all();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->migration] = (int) $row->batch;
        }
        return $out;
    }

    private function getMigrationsFromBatch(int $batch): array
    {
        return DB::table($this->migrationsTable)
            ->where('batch', '=', $batch)
            ->orderBy('migration', 'asc')
            ->pluck('migration');
    }

    /** Batches in descending order — for rollback / reset. */
    /**
     * The last batch, newest migration first — one migrate's worth of work.
     *
     * @return array<int, string>
     */
    private function getLastBatchDescending(): array
    {
        $batches = $this->getBatchesDescending();

        return $batches === []
            ? []
            : array_reverse($this->getMigrationsFromBatch($batches[0]));
    }

    /**
     * The last $count migrations, newest first, whichever batches they are on.
     *
     * @return array<int, string>
     */
    private function getLastMigrations(int $count): array
    {
        return DB::table($this->migrationsTable)
            ->orderBy('batch', 'desc')
            ->orderBy('migration', 'desc')
            ->limit($count)
            ->pluck('migration');
    }

    private function getBatchesDescending(): array
    {
        $rows = DB::table($this->migrationsTable)
            ->orderBy('batch', 'desc')
            ->get()
            ->all();

        $seen = [];
        foreach ($rows as $row) {
            $seen[(int) $row->batch] = true;
        }
        return array_keys($seen);
    }

    private function getAllTables(): array
    {
        // Driver-aware listing — the schema grammar reads sqlite_master on
        // SQLite and information_schema on MySQL. Rows expose `table_name`.
        return SchemaBuilder::getTables();
    }

    /**
     * Toggle foreign-key enforcement on the active connection so all tables can
     * be dropped regardless of FK dependency order (see freshMigrations).
     * Driver-aware — MySQL/MariaDB use SET FOREIGN_KEY_CHECKS, SQLite uses
     * PRAGMA foreign_keys — matching Laravel's Schema disable/enable helpers.
     */
    private function setForeignKeyChecks(bool $enabled): void
    {
        $sql = match ($this->databaseDriver()) {
            'sqlite' => 'PRAGMA foreign_keys = ' . ($enabled ? '1' : '0'),
            default  => 'SET FOREIGN_KEY_CHECKS=' . ($enabled ? '1' : '0'),
        };

        DB::statement($sql);
    }

    /** The active PDO driver name ('mysql', 'sqlite', …); defaults to mysql. */
    private function databaseDriver(): string
    {
        try {
            return (string) DB::connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable) {
            return 'mysql';
        }
    }

    private function recordMigration(string $migration, int $batch): void
    {
        DB::table($this->migrationsTable)->insert(['migration' => $migration, 'batch' => $batch]);
    }

    private function removeMigration(string $migration): void
    {
        DB::table($this->migrationsTable)->where('migration', '=', $migration)->delete();
    }

    private function getNextBatchNumber(): int
    {
        return ((int) DB::table($this->migrationsTable)->max('batch')) + 1;
    }

    private function ensureMigrationsTableExists(): int
    {
        $schema = $this->schema;

        if (!$schema->hasTable($this->migrationsTable)) {
            $schema->create($this->migrationsTable, function ($table) {
                $table->id();
                $table->string('migration');
                $table->integer('batch');
            });
        }

        return ExitCode::SUCCESS;
    }

    // ── flag parsing + production guard ───────────────────────────────

    /** True if the flag is present (e.g. --force, --step, --all). */
    private function flag(array $args, string $flag): bool
    {
        foreach ($args as $argument) {
            if ($argument === $flag) return true;
            if (str_starts_with($argument, $flag . '=')) return true;
        }
        return false;
    }

    /** Value after `--flag=value`, or null. */
    private function flagValue(array $args, string $flag): ?string
    {
        foreach ($args as $argument) {
            if (str_starts_with($argument, $flag . '=')) {
                return substr($argument, strlen($flag) + 1);
            }
        }
        return null;
    }

    /**
     * Destructive commands abort in production unless --force is passed.
     *
     * The gate itself lives in ConfirmsInProduction so every destructive verb
     * in the framework asks the same question; this names the verb for it.
     */
    private function confirmDestructive(string $verb, array $args): bool
    {
        return $this->confirmToProceed($verb, $args, "migrate:{$verb}");
    }
    /** Report an unrecognised signature and fail the invocation. */
    private function invalidSignature(string $message): int
    {
        $this->output->error($message);

        return ExitCode::INVALID;
    }
}
