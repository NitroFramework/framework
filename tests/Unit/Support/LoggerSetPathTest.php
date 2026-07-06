<?php

namespace Tests\Unit\Support;

use Nitro\Support\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Logger::setPath used to call is_dir() + mkdir() on every Application
 * construction. Now it short-circuits when the path is unchanged and caches
 * verified directory results so worker mode + repeated boots don't pay the
 * syscall.
 */
class LoggerSetPathTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset static state between tests so verifiedDirs doesn't leak.
        $verified = new ReflectionProperty(Logger::class, 'verifiedDirs');
        $verified->setValue(null, []);
        $logPath = new ReflectionProperty(Logger::class, 'logPath');
        $logPath->setValue(null, null);
    }

    public function test_set_path_marks_directory_verified(): void
    {
        $dir = sys_get_temp_dir() . '/nitro_logger_test_' . uniqid();
        $file = $dir . '/log.log';

        Logger::setPath($file);

        $verified = new ReflectionProperty(Logger::class, 'verifiedDirs');
        $this->assertArrayHasKey($dir, $verified->getValue(null));
        $this->assertDirectoryExists($dir);

        @rmdir($dir);
    }

    public function test_repeated_calls_with_same_path_are_a_noop(): void
    {
        $dir = sys_get_temp_dir() . '/nitro_logger_test_' . uniqid();
        $file = $dir . '/log.log';

        Logger::setPath($file);
        $this->assertDirectoryExists($dir);

        // Delete the dir then call setPath again with the SAME path; the
        // short-circuit should kick in and we should NOT recreate the dir.
        @rmdir($dir);

        Logger::setPath($file);

        $this->assertDirectoryDoesNotExist($dir);
    }

    public function test_changing_path_creates_new_dir(): void
    {
        $dirA = sys_get_temp_dir() . '/nitro_logger_a_' . uniqid();
        $dirB = sys_get_temp_dir() . '/nitro_logger_b_' . uniqid();

        Logger::setPath($dirA . '/log.log');
        Logger::setPath($dirB . '/log.log');

        $this->assertDirectoryExists($dirB);

        @rmdir($dirA);
        @rmdir($dirB);
    }
}
