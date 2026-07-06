<?php

namespace Tests\Unit\Database;

use Nitro\Database\Connection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the charset/collation guard added to Connection rejects values
 * containing anything other than identifier-safe characters before they reach
 * SET NAMES interpolation.
 */
class ConnectionCharsetTest extends TestCase
{
    protected function assertSafe(string $charset, string $collation): void
    {
        $conn = new Connection([]);
        $m = new ReflectionMethod(Connection::class, 'assertSafeCharsetAndCollation');
        $m->invoke($conn, $charset, $collation);
        $this->addToAssertionCount(1);
    }

    protected function expectUnsafe(string $charset, string $collation): void
    {
        $conn = new Connection([]);
        $m = new ReflectionMethod(Connection::class, 'assertSafeCharsetAndCollation');
        $this->expectException(\InvalidArgumentException::class);
        $m->invoke($conn, $charset, $collation);
    }

    public function test_default_values_pass(): void
    {
        $this->assertSafe('utf8mb4', 'utf8mb4_unicode_ci');
    }

    public function test_underscore_and_digits_pass(): void
    {
        $this->assertSafe('latin1', 'latin1_swedish_ci');
        $this->assertSafe('utf8mb3', 'utf8mb3_general_ci');
    }

    public function test_quote_in_charset_throws(): void
    {
        $this->expectUnsafe("utf8mb4'; DROP TABLE users; --", 'utf8mb4_unicode_ci');
    }

    public function test_quote_in_collation_throws(): void
    {
        $this->expectUnsafe('utf8mb4', "utf8mb4_unicode_ci'; DROP TABLE users; --");
    }

    public function test_hyphen_is_rejected(): void
    {
        $this->expectUnsafe('utf8-mb4', 'utf8mb4_unicode_ci');
    }

    public function test_whitespace_is_rejected(): void
    {
        $this->expectUnsafe('utf8mb4 ', 'utf8mb4_unicode_ci');
    }

    public function test_empty_is_rejected(): void
    {
        $this->expectUnsafe('', 'utf8mb4_unicode_ci');
    }
}
