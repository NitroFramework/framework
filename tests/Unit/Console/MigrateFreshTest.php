<?php

namespace Tests\Unit\Console;

use Nitro\Console\Commands\MigrationCommands;
use Nitro\Console\Commands\SeederCommands;
use Nitro\Console\OutputFormatter;
use Nitro\Database\DB;
use Nitro\Database\Migration\MigrationPathRegistry;
use Nitro\Database\Schema\SchemaBuilder as Schema;
use Nitro\Database\Schema\SchemaCache;
use Nitro\Foundation\Config;
use Nitro\Foundation\PathRegistry;
use PHPUnit\Framework\TestCase;

/**
 * migrate:fresh must drop every table regardless of foreign-key dependency
 * order. It used to drop tables in listing order with FK enforcement on, so a
 * parent table still referenced by a child's FK could not be dropped — the loop
 * failed partway and left the schema half-dropped. The fix disables FK checks
 * around the drop loop (SET FOREIGN_KEY_CHECKS / PRAGMA foreign_keys), mirroring
 * Laravel's Schema::dropAllTables().
 *
 * Reproduced on SQLite: with foreign_keys ON, DROP TABLE on a parent whose rows
 * are referenced by a child performs an implicit row delete that violates the
 * child's FK — exactly the MySQL failure in miniature.
 */
class MigrateFreshTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite required');
        }
        DB::configure(['driver' => 'sqlite', 'database' => ':memory:']);
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }

        // migrate:fresh flips SchemaCache into bypass mode for the run (so
        // migrations see live schema, not a stale cache). A real CLI process
        // exits right after; in the test runner that static flag would leak into
        // later tests (e.g. SchemaCacheTest), so reset it here.
        SchemaCache::bypass(false);
        SchemaCache::flushMemo();

        DB::configure(['driver' => 'sqlite', 'database' => ':memory:']);
    }

    public function test_fresh_drops_all_tables_despite_foreign_key_references(): void
    {
        // Parent + child with a FK, each holding a referencing row so a naive
        // parent-first drop (FK enforced) fails.
        Schema::create('categories', function ($t) {
            $t->id();
            $t->string('name');
        });
        Schema::create('posts', function ($t) {
            $t->id();
            $t->foreignId('category_id')->constrained('categories');
        });
        Schema::create('migrations', function ($t) {
            $t->id();
            $t->string('migration');
            $t->integer('batch')->default(1);
        });

        DB::table('categories')->insert(['name' => 'PHP']);
        DB::table('posts')->insert(['category_id' => 1]);

        $this->assertTrue(Schema::hasTable('categories'));
        $this->assertTrue(Schema::hasTable('posts'));

        ob_start();
        $this->makeCommand()->handle('migrate:fresh', []); // env=testing → no --force
        ob_end_clean();

        // Both dropped — the FK reference no longer blocks the parent drop.
        $this->assertFalse(Schema::hasTable('categories'), 'parent table should be dropped despite the FK from posts');
        $this->assertFalse(Schema::hasTable('posts'), 'child table should be dropped');
    }

    private function makeCommand(): MigrationCommands
    {
        $this->tmpDir = sys_get_temp_dir() . '/nitro-fresh-' . bin2hex(random_bytes(4));
        @mkdir($this->tmpDir, 0775, true);

        $paths = new class($this->tmpDir) extends PathRegistry {
            public function __construct(private string $dir) {}
            public function migrations(string $path = ''): string { return $this->dir; }
        };

        // No --seed, so the seeder is never invoked — a no-op stub is enough.
        $seeder = new class extends SeederCommands {
            public function __construct() {}
        };

        $registry = new MigrationPathRegistry();
        $registry->add($this->tmpDir); // empty → migrate run after drop has nothing pending

        return new MigrationCommands(
            schema:         (new \ReflectionClass(Schema::class))->newInstanceWithoutConstructor(),
            config:         Config::fromArray(['app' => ['env' => 'testing']]),
            output:         new OutputFormatter(),
            seeder:         $seeder,
            migrationPaths: $registry,
            paths:          $paths,
        );
    }
}
