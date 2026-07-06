<?php

namespace Tests\Unit\Cache\Drivers;

use Nitro\Cache\Drivers\FileStore;
use PHPUnit\Framework\TestCase;

class FileStoreTest extends TestCase
{
    protected FileStore $store;
    protected string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/nitro_cache_test_' . uniqid();
        $this->store = new FileStore($this->cacheDir);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        $this->deleteDirectory($this->cacheDir);
    }

    // -------------------------------------------------------------------------
    // Get / Put
    // -------------------------------------------------------------------------

    public function test_it_returns_null_for_missing_key(): void
    {
        $this->assertNull($this->store->get('nonexistent'));
    }

    public function test_it_can_store_and_retrieve_a_string(): void
    {
        $this->store->put('greeting', 'hello', 3600);

        $this->assertSame('hello', $this->store->get('greeting'));
    }

    public function test_it_can_store_and_retrieve_an_array(): void
    {
        $data = ['id' => 1, 'name' => 'Test', 'scores' => [90, 85, 95]];

        $this->store->put('student', $data, 3600);

        $this->assertSame($data, $this->store->get('student'));
    }

    public function test_it_can_store_and_retrieve_integers(): void
    {
        $this->store->put('count', 42, 3600);

        $this->assertSame(42, $this->store->get('count'));
    }

    public function test_it_can_store_and_retrieve_floats(): void
    {
        $this->store->put('price', 19.99, 3600);

        $this->assertSame(19.99, $this->store->get('price'));
    }

    public function test_it_can_store_boolean_true(): void
    {
        $this->store->put('flag', true, 3600);

        $this->assertTrue($this->store->get('flag'));
    }

    // -------------------------------------------------------------------------
    // Many
    // -------------------------------------------------------------------------

    public function test_it_can_get_many_values(): void
    {
        $this->store->put('a', 'alpha', 3600);
        $this->store->put('b', 'bravo', 3600);

        $result = $this->store->many(['a', 'b', 'missing']);

        $this->assertSame('alpha', $result['a']);
        $this->assertSame('bravo', $result['b']);
        $this->assertNull($result['missing']);
    }

    public function test_it_can_put_many_values(): void
    {
        $this->store->putMany([
            'x' => 'x-ray',
            'y' => 'yankee',
            'z' => 'zulu',
        ], 3600);

        $this->assertSame('x-ray', $this->store->get('x'));
        $this->assertSame('yankee', $this->store->get('y'));
        $this->assertSame('zulu', $this->store->get('z'));
    }

    // -------------------------------------------------------------------------
    // Expiration
    // -------------------------------------------------------------------------

    public function test_expired_items_return_null(): void
    {
        $this->store->put('old', 'stale', 0);

        $this->assertNull($this->store->get('old'));
    }

    public function test_valid_items_are_returned(): void
    {
        $this->store->put('fresh', 'data', 9999);

        $this->assertSame('data', $this->store->get('fresh'));
    }

    // -------------------------------------------------------------------------
    // Forever
    // -------------------------------------------------------------------------

    public function test_forever_stores_value(): void
    {
        $this->store->forever('permanent', 'stays');

        $this->assertSame('stays', $this->store->get('permanent'));
    }

    // -------------------------------------------------------------------------
    // Forget
    // -------------------------------------------------------------------------

    public function test_forget_removes_item(): void
    {
        $this->store->put('temp', 'gone soon', 3600);

        $this->assertTrue($this->store->forget('temp'));
        $this->assertNull($this->store->get('temp'));
    }

    public function test_forget_returns_false_for_missing_key(): void
    {
        $this->assertFalse($this->store->forget('never_existed'));
    }

    // -------------------------------------------------------------------------
    // Flush
    // -------------------------------------------------------------------------

    public function test_flush_removes_all_items(): void
    {
        $this->store->put('one', 1, 3600);
        $this->store->put('two', 2, 3600);
        $this->store->forever('three', 3);

        $this->assertTrue($this->store->flush());

        $this->assertNull($this->store->get('one'));
        $this->assertNull($this->store->get('two'));
        $this->assertNull($this->store->get('three'));
    }

    // -------------------------------------------------------------------------
    // Increment / Decrement
    // -------------------------------------------------------------------------

    public function test_increment_creates_key_starting_at_value(): void
    {
        $result = $this->store->increment('hits');

        $this->assertSame(1, $result);
    }

    public function test_increment_existing_value(): void
    {
        $this->store->put('hits', 10, 3600);

        $result = $this->store->increment('hits', 5);

        $this->assertSame(15, $result);
    }

    public function test_decrement_existing_value(): void
    {
        $this->store->put('stock', 50, 3600);

        $result = $this->store->decrement('stock', 3);

        $this->assertSame(47, $result);
    }

    // -------------------------------------------------------------------------
    // Overwrite
    // -------------------------------------------------------------------------

    public function test_put_overwrites_existing_value(): void
    {
        $this->store->put('key', 'old', 3600);
        $this->store->put('key', 'new', 3600);

        $this->assertSame('new', $this->store->get('key'));
    }

    // -------------------------------------------------------------------------
    // File System
    // -------------------------------------------------------------------------

    public function test_cache_directory_is_created_automatically(): void
    {
        $dir = sys_get_temp_dir() . '/nitro_new_dir_' . uniqid();

        $store = new FileStore($dir);
        $store->put('test', 'value', 3600);

        $this->assertDirectoryExists($dir);
        $this->assertSame('value', $store->get('test'));

        // Clean up
        $this->deleteDirectory($dir);
    }

    public function test_files_are_stored_in_hashed_subdirectories(): void
    {
        $this->store->put('test_key', 'value', 3600);

        // The directory should have subdirectories (hash-based)
        $items = scandir($this->cacheDir);
        $subdirs = array_filter($items, fn($item) =>
            $item !== '.' && $item !== '..' && is_dir($this->cacheDir . '/' . $item)
        );

        $this->assertNotEmpty($subdirs, 'Cache should create hashed subdirectories');
    }

    // -------------------------------------------------------------------------
    // Prefix
    // -------------------------------------------------------------------------

    public function test_prefix_is_returned(): void
    {
        $store = new FileStore($this->cacheDir, 'myapp:');

        $this->assertSame('myapp:', $store->getPrefix());
    }

    public function test_default_prefix_is_empty(): void
    {
        $this->assertSame('', $this->store->getPrefix());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}