<?php

namespace Tests\Unit\Database;

use Nitro\Container\Container;
use Nitro\Database\Schema\SchemaCache;
use Nitro\Foundation\Application;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the cache-read path independent of any database connection.
 * We seed a fake schema.php in the cache directory and assert every
 * SchemaCache method serves from it.
 */
class SchemaCacheTest extends TestCase
{
    private string $cacheFile;

    protected function setUp(): void
    {
        Container::reset();
        (new Application(dirname(__DIR__, 3)))->bootstrap();

        $this->cacheFile = Container::getInstance()->get('paths')->cache('schema.php');
        SchemaCache::flushMemo();
        if (is_file($this->cacheFile)) {
            // Snapshot so we restore after the test.
            $this->backup = file_get_contents($this->cacheFile);
        } else {
            $this->backup = null;
        }
    }

    protected function tearDown(): void
    {
        // Restore (or remove) the file so we don't litter for other tests
        if ($this->backup !== null) {
            file_put_contents($this->cacheFile, $this->backup);
        } else {
            @unlink($this->cacheFile);
        }
        SchemaCache::flushMemo();
        restore_error_handler();
        restore_exception_handler();
        Container::reset();
    }

    private ?string $backup = null;

    public function test_returns_null_when_no_cache_file_exists(): void
    {
        @unlink($this->cacheFile);
        SchemaCache::flushMemo();

        $this->assertNull(SchemaCache::tables());
        $this->assertNull(SchemaCache::columnListing('students'));
        $this->assertNull(SchemaCache::columns('students'));
        $this->assertNull(SchemaCache::indexes('students'));
        $this->assertNull(SchemaCache::foreignKeys('students'));
        $this->assertNull(SchemaCache::hasTable('students'));
        $this->assertNull(SchemaCache::hasColumn('students', 'id'));
    }

    public function test_serves_table_listing_from_cache(): void
    {
        $this->seedCache([
            'table_names' => ['students', 'courses'],
        ]);

        $this->assertTrue(SchemaCache::hasTable('students'));
        $this->assertTrue(SchemaCache::hasTable('courses'));
        $this->assertFalse(SchemaCache::hasTable('unknown_table'));
    }

    public function test_serves_column_listing_from_cache(): void
    {
        $this->seedCache([
            'column_listing' => [
                'students' => ['id', 'name', 'email'],
            ],
        ]);

        $this->assertSame(['id', 'name', 'email'], SchemaCache::columnListing('students'));
        $this->assertNull(SchemaCache::columnListing('other_table'));
    }

    public function test_has_column_uses_cached_listing(): void
    {
        $this->seedCache([
            'column_listing' => [
                'students' => ['id', 'name', 'email'],
            ],
        ]);

        $this->assertTrue(SchemaCache::hasColumn('students', 'email'));
        $this->assertFalse(SchemaCache::hasColumn('students', 'password'));
    }

    public function test_columns_returns_objects_for_compatibility(): void
    {
        $this->seedCache([
            'table_columns' => [
                'students' => [
                    ['column_name' => 'id',   'data_type' => 'int'],
                    ['column_name' => 'name', 'data_type' => 'varchar'],
                ],
            ],
        ]);

        $cols = SchemaCache::columns('students');
        $this->assertCount(2, $cols);
        $this->assertIsObject($cols[0]);
        $this->assertSame('id', $cols[0]->column_name);
        $this->assertSame('varchar', $cols[1]->data_type);
    }

    public function test_clear_removes_cache_file_and_invalidates_memo(): void
    {
        $this->seedCache(['table_names' => ['students']]);
        $this->assertTrue(SchemaCache::hasTable('students'));

        SchemaCache::clear();
        $this->assertFileDoesNotExist($this->cacheFile);
        $this->assertNull(SchemaCache::hasTable('students'));
    }

    public function test_bypass_skips_cache_lookups(): void
    {
        $this->seedCache(['table_names' => ['students']]);
        $this->assertTrue(SchemaCache::hasTable('students'));

        SchemaCache::bypass(true);
        $this->assertNull(SchemaCache::hasTable('students'),
            'bypass(true) should hide cache so live queries run instead');

        SchemaCache::bypass(false);
        SchemaCache::flushMemo();
        $this->assertTrue(SchemaCache::hasTable('students'),
            'bypass(false) should restore the cache lookup path');
    }

    private function seedCache(array $data): void
    {
        @mkdir(dirname($this->cacheFile), 0755, true);
        file_put_contents(
            $this->cacheFile,
            "<?php\nreturn " . var_export($data, true) . ";\n",
        );
        SchemaCache::flushMemo();
    }
}
