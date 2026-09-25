<?php

namespace Tests\Unit\Console;

use Nitro\Console\Commands\MigrationCommands;
use Nitro\Console\Commands\SeederCommands;
use Nitro\Console\OutputFormatter;
use Nitro\Database\DB;
use Nitro\Database\Migration\MigrationPathRegistry;
use Nitro\Database\Schema\SchemaBuilder as Schema;
use Nitro\Database\Schema\SchemaCache;
use Nitro\Database\SQLiteDatabaseDoesNotExistException;
use Nitro\Foundation\Config;
use Nitro\Foundation\PathRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * migrate with a SQLite file that is not there yet.
 *
 * The connection refuses a missing file rather than letting PDO create an
 * empty one at a mistyped path, so migrate, whose job is to build the schema,
 * offers to create it: without asking under --force, never under
 * --no-interaction.
 */
class MigrateMissingSqliteDatabaseTest extends TestCase
{
    private string $dir;

    private string $database;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nitro-missing-db-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);

        $this->database = $this->dir . '/database.sqlite';

        DB::configure(['driver' => 'sqlite', 'database' => $this->database]);
    }

    protected function tearDown(): void
    {
        SchemaCache::bypass(false);
        SchemaCache::flushMemo();

        DB::configure(['driver' => 'sqlite', 'database' => ':memory:']);

        @unlink($this->database);
        @rmdir($this->dir);
    }

    public function test_force_creates_the_file_and_migrates(): void
    {
        ob_start();
        $code = $this->command()->handle('migrate', ['--force']);
        ob_end_clean();

        $this->assertSame(0, $code);
        $this->assertFileExists($this->database);
        $this->assertTrue(Schema::hasTable('migrations'));
    }

    public function test_no_interaction_leaves_the_file_missing(): void
    {
        try {
            $this->command()->handle('migrate', ['--no-interaction']);

            $this->fail('A missing database should be reported.');
        } catch (SQLiteDatabaseDoesNotExistException $exception) {
            $this->assertSame($this->database, $exception->path);
            $this->assertFileDoesNotExist($this->database);
        }
    }

    /** Reading the status is no reason to create a database. */
    public function test_status_does_not_create_it_even_with_force(): void
    {
        $this->expectException(SQLiteDatabaseDoesNotExistException::class);

        try {
            $this->command()->handle('migrate:status', ['--force']);
        } finally {
            $this->assertFileDoesNotExist($this->database);
        }
    }

    private function command(): MigrationCommands
    {
        $paths = new class($this->dir) extends PathRegistry {
            public function __construct(private string $dir) {}
            public function migrations(string $path = ''): string { return $this->dir; }
        };

        $seeder = new class extends SeederCommands {
            public function __construct() {}
        };

        $registry = new MigrationPathRegistry();
        $registry->add($this->dir);

        return new MigrationCommands(
            schema:         (new ReflectionClass(Schema::class))->newInstanceWithoutConstructor(),
            config:         Config::fromArray(['app' => ['env' => 'testing']]),
            output:         new OutputFormatter(),
            seeder:         $seeder,
            migrationPaths: $registry,
            paths:          $paths,
        );
    }
}
