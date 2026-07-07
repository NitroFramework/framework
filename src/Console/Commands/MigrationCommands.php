<?php

namespace Nitro\Console\Commands;

use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Database\DB;
use Nitro\Database\Migration\MigrationPathRegistry;
use Nitro\Database\Schema\SchemaBuilder;
use Nitro\Database\Schema\SchemaCache;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\PathRegistry;

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

    public function getCommands(): array
    {
        return [
            'make:migration'    => 'Generate a new migration file',
            'migrate:install'   => 'Create the migrations tracking table',
            'migrate:run'       => 'Run all pending migrations',
            'migrate:rollback'  => 'Rollback the last batch (or N batches via --step=N)',
            'migrate:reset'     => 'Rollback ALL migrations',
            'migrate:refresh'   => 'Rollback all migrations then re-run them',
            'migrate:fresh'     => 'Drop all tables and re-run all migrations',
            'migrate:status'    => 'Show the status of each migration',
            'migrate:mark-ran'  => 'Record migration(s) as ran without executing them',
        ];
    }

    public function handle(string $command, array $arguments = []): void
    {
        // make:migration runs against the filesystem only — no DB needed.
        if ($command === 'make:migration') {
            $this->makeMigration($arguments);
            return;
        }

        // Migrations mutate the schema, so the optimize-time schema cache must
        // not be trusted here: read live during the command (so e.g. the
        // migrations-table check sees reality, not a stale cache), and drop the
        // cached file afterwards so the next process boots with fresh schema.
        SchemaCache::bypass(true);

        try {
            $this->ensureMigrationsTableExists();

            match ($command) {
                'migrate:install'   => $this->output->success("Migrations table is ready."),
                'migrate:run'       => $this->runMigrations($arguments),
                'migrate:rollback'  => $this->rollbackMigrations($arguments),
                'migrate:reset'     => $this->resetMigrations($arguments),
                'migrate:refresh'   => $this->refreshMigrations($arguments),
                'migrate:fresh'     => $this->freshMigrations($arguments),
                'migrate:status'    => $this->showStatus(),
                'migrate:mark-ran'  => $this->markRan($arguments),
                default             => $this->output->error("Unknown migration command: {$command}")
            };
        } finally {
            // Invalidate the on-disk schema cache after any command that could
            // have changed the schema (even a partial/failed run). status is
            // read-only, so it leaves the cache untouched.
            if ($command !== 'migrate:status') {
                SchemaCache::clear();
            }
        }
    }

    // ── make:migration ────────────────────────────────────────────────

    private function makeMigration(array $arguments): void
    {
        $name = $arguments[0] ?? null;
        if (!$name) {
            $this->output->error("Usage: make:migration <name>");
            $this->output->writeln("Example: make:migration create_orders_table");
            return;
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
            return;
        }

        $timestamp = date('Y_m_d_His');
        $filename  = "{$timestamp}_{$snake}.php";
        $path      = $this->migrationsPath . '/' . $filename;

        if (!is_dir($this->migrationsPath)) {
            mkdir($this->migrationsPath, 0775, true);
        }

        if (file_exists($path)) {
            $this->output->error("File already exists: {$filename}");
            return;
        }

        // Guess the table from the migration name so the create-stub is useful
        // out of the box ("create_orders_table" → "orders"). When the name
        // doesn't imply a table, emit a blank migration rather than scaffolding
        // a bogus create('TODO_table_name').
        $tableGuess = $this->guessTableName($snake);

        file_put_contents($path, $tableGuess !== null
            ? $this->migrationStub($tableGuess)
            : $this->blankMigrationStub());
        $this->output->success("Created: database/migrations/{$filename}");
    }

    /** The table a create-migration targets, or null when the name doesn't imply one. */
    private function guessTableName(string $snake): ?string
    {
        if (preg_match('/^create_(.+?)_table$/', $snake, $m)) return $m[1];
        if (preg_match('/^add_.+_to_(.+?)_table$/', $snake, $m)) return $m[1];
        if (preg_match('/^drop_(.+?)_table$/', $snake, $m)) return $m[1];
        return null;
    }

    private function migrationStub(string $table): string
    {
        return <<<PHP
        <?php

        use Nitro\\Database\\Schema\\SchemaBuilder;

        return new class {
            public function up(SchemaBuilder \$schema): void
            {
                \$schema->create('{$table}', function (\$table) {
                    \$table->id();
                    // \$table->string('name');
                    \$table->timestamps();
                });
            }

            public function down(SchemaBuilder \$schema): void
            {
                \$schema->dropIfExists('{$table}');
            }
        };

        PHP;
    }

    /**
     * A blank migration — empty up()/down() with the SchemaBuilder in hand.
     * Used when the name doesn't map to a create_/add_to_/drop_ table, so the
     * developer fills in the intent instead of deleting a wrong-guessed table.
     */
    private function blankMigrationStub(): string
    {
        return <<<PHP
        <?php

        use Nitro\\Database\\Schema\\SchemaBuilder;

        return new class {
            public function up(SchemaBuilder \$schema): void
            {
                //
            }

            public function down(SchemaBuilder \$schema): void
            {
                //
            }
        };

        PHP;
    }

    // ── migrate:run ───────────────────────────────────────────────────

    private function runMigrations(array $arguments): void
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
            return;
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
                    $migration->up($this->schema);
                });
                $this->output->writeln(" (would execute " . count($log) . " statements)");
                $this->dumpPretendLog($log);
            } else {
                $migration->up($this->schema);
                $this->recordMigration($file, $batch);
                $this->output->success(" DONE");
            }
        } catch (\Throwable $e) {
            $this->output->error(" FAILED");
            $this->output->error("Error: " . $e->getMessage());
            throw $e;
        }
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

    private function rollbackMigrations(array $arguments): void
    {
        if (!$this->confirmDestructive('rollback', $arguments)) return;

        $steps   = (int) ($this->flagValue($arguments, '--step') ?? 1);
        $pretend = $this->flag($arguments, '--pretend');
        if ($steps < 1) $steps = 1;

        $this->output->info($pretend
            ? "Rolling back (--pretend)...\n"
            : "Rolling back migrations...\n");

        $batches = $this->getBatchesDescending();
        if (empty($batches)) {
            $this->output->success("Nothing to rollback!");
            return;
        }

        $toRollBack = array_slice($batches, 0, $steps);
        foreach ($toRollBack as $batch) {
            foreach (array_reverse($this->getMigrationsFromBatch($batch)) as $migration) {
                $this->rollbackMigration($migration, $pretend);
            }
        }

        $this->output->success($pretend
            ? "\n--pretend complete (no changes applied)."
            : "\nRollback completed successfully!");
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
                    $migration->down($this->schema);
                });
                $this->output->writeln(" (would execute " . count($log) . " statements)");
                $this->dumpPretendLog($log);
            } else {
                $migration->down($this->schema);
                $this->removeMigration($file);
                $this->output->success(" DONE");
            }
        } catch (\Throwable $e) {
            $this->output->error(" FAILED");
            $this->output->error("Error: " . $e->getMessage());
            throw $e;
        }
    }

    // ── migrate:reset ─────────────────────────────────────────────────

    private function resetMigrations(array $arguments): void
    {
        if (!$this->confirmDestructive('reset', $arguments)) return;

        $this->output->info("Resetting all migrations...\n");

        $batches = $this->getBatchesDescending();
        if (empty($batches)) {
            $this->output->success("Nothing to reset!");
            return;
        }

        foreach ($batches as $batch) {
            foreach (array_reverse($this->getMigrationsFromBatch($batch)) as $migration) {
                $this->rollbackMigration($migration);
            }
        }

        $this->output->success("\nReset completed successfully!");
    }

    // ── migrate:refresh ───────────────────────────────────────────────

    private function refreshMigrations(array $arguments): void
    {
        if (!$this->confirmDestructive('refresh', $arguments)) return;

        // Pass --force through to the underlying reset/run so we don't
        // prompt twice in the same operation.
        $args = array_unique(array_merge($arguments, ['--force']));

        $this->resetMigrations($args);
        $this->runMigrations($args);

        if ($this->flag($arguments, '--seed')) {
            $this->seedAfterMigrations($arguments);
        }
    }

    // ── migrate:fresh ─────────────────────────────────────────────────

    private function freshMigrations(array $arguments): void
    {
        if (!$this->confirmDestructive('fresh', $arguments)) return;

        $this->output->info("Dropping all tables...");

        foreach ($this->getAllTables() as $table) {
            // table names come back as objects via information_schema
            $name = is_object($table) ? ($table->table_name ?? $table->TABLE_NAME ?? null) : $table;
            if (!$name || $name === $this->migrationsTable) continue;
            $this->schema->dropIfExists($name);
            $this->output->write("Dropped: {$name}\n");
        }

        DB::table($this->migrationsTable)->delete();
        $this->output->success("All tables dropped!\n");

        // Run with --force so we don't re-prompt in the run path.
        $this->runMigrations(array_merge($arguments, ['--force']));

        if ($this->flag($arguments, '--seed')) {
            $this->seedAfterMigrations($arguments);
        }
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
        } catch (\Throwable $e) {
            $this->output->error("Seeding failed: " . $e->getMessage());
            throw $e;
        }
    }

    // ── migrate:status ────────────────────────────────────────────────

    private function showStatus(): void
    {
        $files = $this->getMigrationFiles();
        $ranBatchMap = $this->getRanWithBatches();

        if (empty($files)) {
            $this->output->info("No migration files found.");
            return;
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
    }

    // ── migrate:mark-ran ──────────────────────────────────────────────

    private function markRan(array $arguments): void
    {
        $files = $this->getMigrationFiles();
        $ran   = $this->getRanMigrations();
        $pending = array_values(array_diff($files, $ran));

        $all = $this->flag($arguments, '--all');
        $named = array_values(array_filter(
            $arguments,
            fn($a) => !str_starts_with($a, '--')
        ));

        if (!$all && empty($named)) {
            $this->output->error("Usage: migrate:mark-ran <name> [<name> …]");
            $this->output->writeln("       migrate:mark-ran --all      (marks every pending migration as ran)");
            return;
        }

        $targets = $all ? $pending : $named;

        // Validate every requested name actually exists as a migration file
        // — otherwise a typo silently records a nonexistent file as "ran"
        // and the corresponding real migration eventually runs anyway.
        foreach ($targets as $name) {
            if (!in_array($name, $files, true)) {
                $this->output->error("Migration file not found: {$name}");
                return;
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
        return \Nitro\Database\Schema\SchemaBuilder::getTables();
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

    private function ensureMigrationsTableExists(): void
    {
        $schema = $this->schema;

        if (!$schema->hasTable($this->migrationsTable)) {
            $schema->create($this->migrationsTable, function ($table) {
                $table->id();
                $table->string('migration');
                $table->integer('batch');
            });
        }
    }

    // ── flag parsing + production guard ───────────────────────────────

    /** True if the flag is present (e.g. --force, --step, --all). */
    private function flag(array $args, string $flag): bool
    {
        foreach ($args as $a) {
            if ($a === $flag) return true;
            if (str_starts_with($a, $flag . '=')) return true;
        }
        return false;
    }

    /** Value after `--flag=value`, or null. */
    private function flagValue(array $args, string $flag): ?string
    {
        foreach ($args as $a) {
            if (str_starts_with($a, $flag . '=')) {
                return substr($a, strlen($flag) + 1);
            }
        }
        return null;
    }

    /**
     * Destructive commands abort in production unless --force is passed.
     * In other environments they run unconditionally — local/dev is
     * where these get used most. The check is intentionally narrow:
     * "are we in production?" not "is this a destructive verb?"
     */
    private function confirmDestructive(string $verb, array $args): bool
    {
        $env = $this->config->get('app.env');
        if ($env !== 'production') {
            return true;
        }
        if ($this->flag($args, '--force')) {
            return true;
        }
        $this->output->error(
            "Refusing to {$verb} in production without --force. "
            . "Re-run as: php nitro migrate:{$verb} --force"
        );
        return false;
    }
}
