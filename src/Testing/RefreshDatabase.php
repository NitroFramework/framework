<?php

namespace Nitro\Testing;

use Nitro\Database\DB;
use Nitro\Database\Migration\MigrationPathRegistry;
use Nitro\Database\Schema\SchemaBuilder;

/**
 * Give each test a fresh, empty database built from the application's own
 * migrations.
 *
 * In memory and rebuilt per test rather than rolled back in a transaction. It
 * costs more, and it buys the thing a compliance product cannot do without:
 * code under test can use transactions itself. An action that takes a seat
 * under lockForUpdate() inside a transaction cannot be tested inside an outer
 * transaction that the test then rolls back — the nesting either swallows the
 * behaviour being tested or deadlocks.
 *
 * Built from the migrations, not from a schema dump, so a migration that is
 * wrong fails the suite rather than being routed around by it.
 */
trait RefreshDatabase
{
    /**
     * Compiled migration closures, keyed by path. Requiring the same forty
     * files for every test in a suite is the slowest part of this trait, and
     * PHP will only evaluate each file once anyway — this keeps the objects.
     *
     * @var array<string, object>|null
     */
    private static ?array $migrationCache = null;

    protected function setUpRefreshDatabase(): void
    {
        DB::disconnect();
        DB::configure($this->testDatabaseConfig());

        $schema = new SchemaBuilder();

        foreach ($this->migrations() as $migration) {
            $migration->up($schema);
        }
    }

    /**
     * Override to test against something other than in-memory SQLite — a real
     * MySQL, when the thing under test is MySQL-specific.
     */
    protected function testDatabaseConfig(): array
    {
        return ['driver' => 'sqlite', 'database' => ':memory:'];
    }

    /**
     * Every migration the application knows about, in filename order across all
     * registered paths — the same order the migrate command uses, so the schema
     * a test runs against is the schema a deploy would produce.
     *
     * @return array<int, object>
     */
    protected function migrations(): array
    {
        if (self::$migrationCache !== null) {
            return array_values(self::$migrationCache);
        }

        $files = [];

        foreach ($this->migrationPaths() as $path) {
            foreach (glob(rtrim($path, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
                $files[basename($file)] = $file;
            }
        }

        ksort($files);

        self::$migrationCache = [];

        foreach ($files as $name => $file) {
            $migration = require $file;

            // A migration that returns nothing is a mistake worth naming here:
            // silently skipping it produces a missing table several tests later.
            if (!is_object($migration) || !method_exists($migration, 'up')) {
                throw new \RuntimeException("Migration [{$name}] did not return an object with an up() method.");
            }

            self::$migrationCache[$file] = $migration;
        }

        return array_values(self::$migrationCache);
    }

    /** @return array<int, string> */
    protected function migrationPaths(): array
    {
        $registry = $this->app ? $this->make(MigrationPathRegistry::class) : null;

        $paths = $registry ? $registry->all() : [];

        return $paths !== [] ? $paths : [$this->basePath() . '/database/migrations'];
    }
}
