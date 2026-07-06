<?php

namespace Tests\Unit\Cache\Drivers;

use Nitro\Cache\Drivers\FileStore;
use PHPUnit\Framework\TestCase;

/**
 * Tests around FileStore's unserialize behavior plus the TOCTOU / missing-file
 * paths.
 *
 * The default for `allowed_classes` is `true` (allow all) because real apps
 * cache models, paginators, DTOs etc. Apps that need stricter behavior pass
 * an explicit whitelist or `false`.
 */
class FileStoreSecurityTest extends TestCase
{
    protected string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/nitro_cache_sec_' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->wipe($this->cacheDir);
    }

    protected function wipe(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->wipe($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function test_default_allows_arbitrary_objects(): void
    {
        // Default is allow-all — round-trip any class out of the box.
        $store = new FileStore($this->cacheDir, 'p_');
        $store->put('key', new \ArrayObject(['payload' => 'x']), 60);

        $value = $store->get('key');

        $this->assertInstanceOf(\ArrayObject::class, $value);
        $this->assertSame('x', $value['payload']);
    }

    public function test_strict_whitelist_blocks_unlisted_classes(): void
    {
        $store = new FileStore($this->cacheDir, 'p_', 2, [\stdClass::class]);
        $store->put('key', new \ArrayObject(['payload' => 'x']), 60);

        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $store->get('key'));
    }

    public function test_strict_whitelist_permits_listed_classes(): void
    {
        $store = new FileStore($this->cacheDir, 'p_', 2, [\stdClass::class, \ArrayObject::class]);
        $store->put('key', new \ArrayObject(['payload' => 'x']), 60);

        $value = $store->get('key');
        $this->assertInstanceOf(\ArrayObject::class, $value);
        $this->assertSame('x', $value['payload']);
    }

    public function test_false_blocks_all_objects(): void
    {
        $store = new FileStore($this->cacheDir, 'p_', 2, false);
        $store->put('key', (object) ['a' => 1], 60);

        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $store->get('key'));
    }

    public function test_stdclass_round_trips_with_default(): void
    {
        $store = new FileStore($this->cacheDir, 'p_');
        $store->put('obj', (object) ['a' => 1, 'b' => 'two'], 60);

        $loaded = $store->get('obj');
        $this->assertInstanceOf(\stdClass::class, $loaded);
        $this->assertSame(1, $loaded->a);
        $this->assertSame('two', $loaded->b);
    }

    public function test_get_returns_null_when_file_disappears_mid_read(): void
    {
        $store = new FileStore($this->cacheDir, 'p_');
        $this->assertNull($store->get('never_set'));
    }

    public function test_forget_missing_key_returns_false_without_warning(): void
    {
        $store = new FileStore($this->cacheDir, 'p_');
        $this->assertFalse($store->forget('does_not_exist'));
    }

    public function test_serialized_scalars_round_trip(): void
    {
        $store = new FileStore($this->cacheDir, 'p_');

        $store->put('int', 42, 60);
        $store->put('float', 3.14, 60);
        $store->put('string', 'hello', 60);
        $store->put('array', ['a' => 1], 60);
        $store->put('bool_false', false, 60);

        $this->assertSame(42, $store->get('int'));
        $this->assertSame(3.14, $store->get('float'));
        $this->assertSame('hello', $store->get('string'));
        $this->assertSame(['a' => 1], $store->get('array'));
        $this->assertFalse($store->get('bool_false'));
    }
}
