<?php

namespace Tests\Unit\Foundation;

use Nitro\Foundation\Application;
use Nitro\Foundation\Bootstrap\LoadEnvironment;
use Nitro\Foundation\PathRegistry;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Confirms the bootstrap step short-circuits when APP_ENV_LOADED is already in
 * the environment, avoiding a Dotenv file open per request in worker mode.
 */
class LoadEnvironmentSkipTest extends TestCase
{
    protected function makeBootstrapper(): LoadEnvironment
    {
        return new LoadEnvironment();
    }

    protected function fakeApp(string $base): Application
    {
        $app = $this->createMock(Application::class);
        $paths = $this->createMock(PathRegistry::class);
        $paths->method('base')->willReturn($base);
        $app->method('paths')->willReturn($paths);
        return $app;
    }

    #[RunInSeparateProcess]
    public function test_skips_when_sentinel_env_is_set(): void
    {
        $_ENV['APP_ENV_LOADED'] = '1';
        $tmp = sys_get_temp_dir() . '/nitro_env_test_' . uniqid();
        mkdir($tmp, 0755, true);
        // Deliberately do NOT create a .env file — if Dotenv ran it would still
        // safeLoad without failing, but we'd see side effects on $_ENV.
        file_put_contents($tmp . '/.env', "FROM_DOTENV=should_not_load\n");

        $this->makeBootstrapper()->bootstrap($this->fakeApp($tmp));

        $this->assertArrayNotHasKey('FROM_DOTENV', $_ENV);
        @unlink($tmp . '/.env');
        @rmdir($tmp);
    }

    #[RunInSeparateProcess]
    public function test_loads_dotenv_when_sentinel_absent(): void
    {
        unset($_ENV['APP_ENV_LOADED'], $_SERVER['APP_ENV_LOADED']);
        putenv('APP_ENV_LOADED');

        $tmp = sys_get_temp_dir() . '/nitro_env_test_' . uniqid();
        mkdir($tmp, 0755, true);
        file_put_contents($tmp . '/.env', "FROM_DOTENV=loaded\n");

        $this->makeBootstrapper()->bootstrap($this->fakeApp($tmp));

        $this->assertSame('loaded', $_ENV['FROM_DOTENV'] ?? null);

        @unlink($tmp . '/.env');
        @rmdir($tmp);
    }
}
