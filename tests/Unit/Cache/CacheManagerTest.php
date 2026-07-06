<?php

namespace Tests\Unit\Cache;

use Nitro\Cache\CacheManager;
use Nitro\Cache\Repository;
use Nitro\Cache\Contracts\StoreInterface;
use Nitro\Cache\Drivers\ArrayStore;
use Nitro\Cache\Drivers\FileStore;
use Nitro\Cache\Drivers\NullStore;
use PHPUnit\Framework\TestCase;

class CacheManagerTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/nitro_cache_mgr_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->deleteDirectory($this->tempDir);
        }
    }

    protected function makeManager(array $config = []): CacheManager
    {
        return new CacheManager(array_merge([
            'default' => 'array',
            'prefix'  => 'test:',
            'stores'  => [
                'array' => [
                    'driver' => 'array',
                ],
                'file' => [
                    'driver' => 'file',
                    'path'   => $this->tempDir,
                ],
                'null' => [
                    'driver' => 'null',
                ],
            ],
        ], $config));
    }

    // -------------------------------------------------------------------------
    // Default Driver
    // -------------------------------------------------------------------------

    public function test_default_driver_is_file_when_not_configured(): void
    {
        $manager = new CacheManager();

        $this->assertSame('file', $manager->getDefaultDriver());
    }

    public function test_default_driver_from_config(): void
    {
        $manager = $this->makeManager();

        $this->assertSame('array', $manager->getDefaultDriver());
    }

    public function test_default_driver_can_be_changed(): void
    {
        $manager = $this->makeManager();

        $manager->setDefaultDriver('null');

        $this->assertSame('null', $manager->getDefaultDriver());
    }

    // -------------------------------------------------------------------------
    // Store Resolution
    // -------------------------------------------------------------------------

    public function test_store_returns_repository_instance(): void
    {
        $manager = $this->makeManager();

        $this->assertInstanceOf(Repository::class, $manager->store());
    }

    public function test_store_resolves_array_driver(): void
    {
        $manager = $this->makeManager();

        $store = $manager->store('array');

        $this->assertInstanceOf(ArrayStore::class, $store->getStore());
    }

    public function test_store_resolves_file_driver(): void
    {
        $manager = $this->makeManager();

        $store = $manager->store('file');

        $this->assertInstanceOf(FileStore::class, $store->getStore());
    }

    public function test_store_resolves_null_driver(): void
    {
        $manager = $this->makeManager();

        $store = $manager->store('null');

        $this->assertInstanceOf(NullStore::class, $store->getStore());
    }

    public function test_store_returns_same_instance_on_repeated_calls(): void
    {
        $manager = $this->makeManager();

        $first  = $manager->store('array');
        $second = $manager->store('array');

        $this->assertSame($first, $second);
    }

    public function test_driver_is_alias_for_store(): void
    {
        $manager = $this->makeManager();

        $this->assertSame(
            $manager->store('array'),
            $manager->driver('array')
        );
    }

    // -------------------------------------------------------------------------
    // Errors
    // -------------------------------------------------------------------------

    public function test_undefined_store_throws_exception(): void
    {
        $manager = $this->makeManager();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache store [nope] is not defined');

        $manager->store('nope');
    }

    public function test_unsupported_driver_throws_exception(): void
    {
        $manager = new CacheManager([
            'stores' => [
                'custom' => [
                    'driver' => 'memcached',
                ],
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache driver [memcached] is not supported');

        $manager->store('custom');
    }

    // -------------------------------------------------------------------------
    // Custom Drivers
    // -------------------------------------------------------------------------

    public function test_extend_registers_custom_driver(): void
    {
        $manager = new CacheManager([
            'default' => 'custom',
            'stores'  => [
                'custom' => [
                    'driver' => 'memory',
                ],
            ],
        ]);

        $manager->extend('memory', function (array $config) {
            return new ArrayStore();
        });

        $store = $manager->store('custom');

        $this->assertInstanceOf(Repository::class, $store);
        $this->assertInstanceOf(ArrayStore::class, $store->getStore());
    }

    // -------------------------------------------------------------------------
    // Forget Driver
    // -------------------------------------------------------------------------

    public function test_forget_driver_clears_resolved_instance(): void
    {
        $manager = $this->makeManager();

        $first = $manager->store('array');
        $manager->forgetDriver('array');
        $second = $manager->store('array');

        $this->assertNotSame($first, $second);
    }

    // -------------------------------------------------------------------------
    // Magic __call Proxy
    // -------------------------------------------------------------------------

    public function test_magic_call_proxies_to_default_store(): void
    {
        $manager = $this->makeManager();

        $manager->put('magic_key', 'magic_value', 3600);

        $this->assertSame('magic_value', $manager->get('magic_key'));
    }

    public function test_magic_call_remember(): void
    {
        $manager = $this->makeManager();

        $result = $manager->remember('computed', 3600, fn() => 42);

        $this->assertSame(42, $result);
        $this->assertSame(42, $manager->get('computed'));
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
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}