<?php

namespace Tests\Unit\Exceptions;

use ErrorException;
use Nitro\Foundation\Bootstrap\HandleExceptions;
use Nitro\Support\Logger;
use PHPUnit\Framework\TestCase;

/**
 * What PHP's own error channel does before any exception exists.
 *
 * The rule that matters: a deprecation is a notice about a FUTURE version, not
 * a failure now. Converting it to a thrown ErrorException meant one deprecation
 * anywhere in vendor/ took the whole request down.
 */
class ErrorHandlingBootstrapTest extends TestCase
{
    private string $logFile;
    private int $errorReporting;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/nitro-deprecation-test-' . getmypid() . '.log';
        @unlink($this->logFile);
        Logger::setPath($this->logFile);

        // PHPUnit narrows error_reporting to fatals (245) so its own handler can
        // own warnings. HandleExceptions::bootstrap() sets E_ALL, so that is the
        // precondition these tests have to reproduce.
        $this->errorReporting = error_reporting(E_ALL);
    }

    protected function tearDown(): void
    {
        error_reporting($this->errorReporting);
        @unlink($this->logFile);
    }

    public function test_a_deprecation_is_logged_and_does_not_throw(): void
    {
        $bootstrapper = new HandleExceptions();

        $bootstrapper->handleError(E_DEPRECATED, 'Implicit conversion is deprecated', '/app/Thing.php', 12);

        $this->addToAssertionCount(1); // did not throw — that is the assertion

        $log = (string) @file_get_contents($this->logFile);
        $this->assertStringContainsString('Implicit conversion is deprecated', $log);
        $this->assertStringContainsString('WARNING', $log);
        $this->assertStringContainsString('deprecation', $log);
    }

    public function test_a_user_deprecation_is_also_survivable(): void
    {
        (new HandleExceptions())->handleError(E_USER_DEPRECATED, 'Call bar() instead', '/app/Foo.php', 3);

        $this->addToAssertionCount(1);
    }

    public function test_a_real_error_still_throws(): void
    {
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('Undefined variable $x');

        (new HandleExceptions())->handleError(E_WARNING, 'Undefined variable $x', '/app/Foo.php', 9);
    }

    public function test_a_silenced_error_does_not_throw(): void
    {
        error_reporting(0); // what the @ operator does

        (new HandleExceptions())->handleError(E_WARNING, 'suppressed', '/app/Foo.php', 9);

        $this->addToAssertionCount(1);
    }

    public function test_a_silenced_deprecation_is_not_logged_either(): void
    {
        error_reporting(E_ALL & ~E_DEPRECATED);

        (new HandleExceptions())->handleError(E_DEPRECATED, 'quiet please', '/app/Foo.php', 9);

        $this->assertStringNotContainsString('quiet please', (string) @file_get_contents($this->logFile));
    }
}

