<?php

namespace Tests\Unit\Foundation;

use Nitro\Foundation\Config;
use PHPUnit\Framework\TestCase;

/**
 * A compiled config cache must be bypassed once .env is edited after it was
 * built (e.g. key:generate rotating APP_KEY) — otherwise the app silently
 * serves stale config. Config::cacheIsFresh is the guard both read paths use.
 */
class ConfigCacheFreshnessTest extends TestCase
{
    private array $tmp = [];

    private function tmpFile(int $mtime): string
    {
        $f = tempnam(sys_get_temp_dir(), 'nitro-cfg-');
        touch($f, $mtime);
        $this->tmp[] = $f;
        return $f;
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
    }

    public function test_stale_when_env_is_newer_than_cache(): void
    {
        $cache = $this->tmpFile(time() - 100);
        $env   = $this->tmpFile(time());
        $this->assertFalse(Config::cacheIsFresh($cache, $env));
    }

    public function test_fresh_when_cache_is_newer_or_equal(): void
    {
        $env   = $this->tmpFile(time() - 100);
        $cache = $this->tmpFile(time());
        $this->assertTrue(Config::cacheIsFresh($cache, $env));
    }

    public function test_fresh_when_no_env_file_exists(): void
    {
        $cache = $this->tmpFile(time());
        $this->assertTrue(Config::cacheIsFresh($cache, '/no/such/.env'));
    }

    public function test_not_fresh_when_cache_file_missing(): void
    {
        $this->assertFalse(Config::cacheIsFresh('/no/such/config.php', '/no/such/.env'));
    }
}
