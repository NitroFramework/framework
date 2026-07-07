<?php

namespace Tests\Unit\Console;

use Nitro\Console\Commands\MigrationCommands;
use Nitro\Console\Commands\SeederCommands;
use Nitro\Console\OutputFormatter;
use Nitro\Database\Migration\MigrationPathRegistry;
use Nitro\Database\Schema\SchemaBuilder;
use Nitro\Foundation\Config;
use Nitro\Foundation\PathRegistry;
use PHPUnit\Framework\TestCase;

/**
 * make:migration is the filesystem-only slice of MigrationCommands — no DB
 * touched, so we can test it in isolation. Covers:
 *
 *   - Timestamped filename (Laravel format: Y_m_d_His_<name>.php)
 *   - Stub contents reference the right table guess
 *   - Different name shapes ("create_X_table", "add_X_to_Y_table", custom)
 *   - Refuses an empty/non-alphanumeric name
 *   - Won't overwrite an existing file (same-second collisions)
 */
class MakeMigrationTest extends TestCase
{
    private string $tmpDir;
    private MigrationCommands $cmd;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/nitro-migrations-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0775, true);

        // PathRegistry has a real constructor — easier to fake just the
        // one method we need than to instantiate it for real.
        $paths = new class($this->tmpDir) extends PathRegistry {
            public function __construct(private string $dir) {
                // Skip the parent constructor — we don't need the real
                // path resolution for these tests.
            }
            public function migrations(string $path = ''): string { return $this->dir; }
        };

        // make:migration is filesystem-only, so the seeder is never touched —
        // a no-op stub is enough. The registry points discovery at the temp dir.
        $seeder = new class extends SeederCommands {
            public function __construct() {}
        };

        $registry = new MigrationPathRegistry();
        $registry->add($this->tmpDir);

        $this->cmd = new MigrationCommands(
            schema:         $this->stubSchema(),
            config:         $this->stubConfig(),
            output:         new OutputFormatter(),
            seeder:         $seeder,
            migrationPaths: $registry,
            paths:          $paths,
        );
    }

    protected function tearDown(): void
    {
        // Recursively remove the temp dir
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
    }

    public function test_creates_file_with_laravel_format_timestamp(): void
    {
        ob_start();
        $this->cmd->handle('make:migration', ['create_orders_table']);
        ob_end_clean();

        $files = glob($this->tmpDir . '/*.php');
        $this->assertCount(1, $files);

        $name = basename($files[0]);
        $this->assertMatchesRegularExpression(
            '/^\d{4}_\d{2}_\d{2}_\d{6}_create_orders_table\.php$/',
            $name,
            'filename must match Laravel format: Y_m_d_His_<snake_name>.php'
        );
    }

    public function test_stub_guesses_table_from_create_X_table_name(): void
    {
        ob_start();
        $this->cmd->handle('make:migration', ['create_orders_table']);
        ob_end_clean();

        $contents = file_get_contents(glob($this->tmpDir . '/*.php')[0]);
        $this->assertStringContainsString("\$schema->create('orders'", $contents);
        $this->assertStringContainsString("\$schema->dropIfExists('orders')", $contents);
    }

    public function test_stub_guesses_table_from_add_X_to_Y_table_name(): void
    {
        ob_start();
        $this->cmd->handle('make:migration', ['add_category_id_to_posts_table']);
        ob_end_clean();

        $contents = file_get_contents(glob($this->tmpDir . '/*.php')[0]);
        $this->assertStringContainsString("'posts'", $contents,
            'add_X_to_Y_table should target the Y table');
    }

    public function test_unrecognized_name_produces_a_blank_migration(): void
    {
        ob_start();
        $this->cmd->handle('make:migration', ['arbitrary_name']);
        ob_end_clean();

        $file     = glob($this->tmpDir . '/*.php')[0];
        $contents = file_get_contents($file);

        // No bogus create('TODO_table_name') scaffold to clean up.
        $this->assertStringNotContainsString('TODO_table_name', $contents);
        $this->assertStringNotContainsString('$schema->create(', $contents);

        // A blank, valid migration: requiring it yields an object with up/down
        // (a parse error would fatal here, so this also proves it's valid PHP).
        $migration = require $file;
        $this->assertTrue(method_exists($migration, 'up'));
        $this->assertTrue(method_exists($migration, 'down'));
    }

    public function test_snake_cases_camelcase_input(): void
    {
        ob_start();
        $this->cmd->handle('make:migration', ['CreateOrdersTable']);
        ob_end_clean();

        $name = basename(glob($this->tmpDir . '/*.php')[0]);
        $this->assertStringEndsWith('_create_orders_table.php', $name,
            'CamelCase input should snake_case → create_orders_table');
    }

    public function test_rejects_empty_name(): void
    {
        ob_start();
        $this->cmd->handle('make:migration', []);
        $output = ob_get_clean();

        $this->assertStringContainsString('Usage:', $output);
        $this->assertCount(0, glob($this->tmpDir . '/*.php'),
            'no file should be created when name is missing');
    }

    public function test_rejects_name_with_no_alphanumerics(): void
    {
        ob_start();
        $this->cmd->handle('make:migration', ['___']);
        $output = ob_get_clean();

        $this->assertStringContainsString('alphanumeric', $output);
        $this->assertCount(0, glob($this->tmpDir . '/*.php'));
    }

    public function test_refuses_to_overwrite_existing_same_second_file(): void
    {
        // Two make:migration calls in the same second collide on filename.
        ob_start();
        $this->cmd->handle('make:migration', ['create_orders_table']);
        $first = ob_get_clean();
        $this->assertStringContainsString('Created:', $first);

        ob_start();
        $this->cmd->handle('make:migration', ['create_orders_table']);
        $second = ob_get_clean();

        $this->assertStringContainsString('already exists', $second,
            'same-second collision should refuse rather than clobber');
        $this->assertCount(1, glob($this->tmpDir . '/*.php'),
            'only the first file should be on disk');
    }

    // ── stubs ─────────────────────────────────────────────────────────

    private function stubSchema(): SchemaBuilder
    {
        // make:migration doesn't touch the schema, but the constructor
        // requires one. A real-but-uninstantiated reflection instance is
        // enough — no methods will be called.
        return (new \ReflectionClass(SchemaBuilder::class))->newInstanceWithoutConstructor();
    }

    private function stubConfig(): Config
    {
        return Config::fromArray(['app' => ['env' => 'testing']]);
    }
}
