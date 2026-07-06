<?php

namespace Tests\Unit\Database;

use Nitro\Database\Query\Paginator;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The paginator resolves the current page through an injected resolver (wired
 * to the request by the HTTP layer) instead of reading $_GET directly — keeping
 * the database layer HTTP-free and testable. Mirrors Laravel's
 * Paginator::currentPageResolver / resolveCurrentPage.
 */
class PaginatorResolverTest extends TestCase
{
    private mixed $original;

    protected function setUp(): void
    {
        $prop = new ReflectionProperty(Paginator::class, 'currentPageResolver');
        $this->original = $prop->getValue();
    }

    protected function tearDown(): void
    {
        // Restore whatever the app boot (or another test) had registered.
        Paginator::currentPageResolverUsing($this->original);
    }

    public function test_resolver_value_is_used(): void
    {
        Paginator::currentPageResolverUsing(fn(string $name) => '3');
        $this->assertSame(3, Paginator::resolveCurrentPage());
    }

    public function test_integer_resolver_value_is_used(): void
    {
        Paginator::currentPageResolverUsing(fn(string $name) => 5);
        $this->assertSame(5, Paginator::resolveCurrentPage());
    }

    public function test_page_name_is_passed_to_resolver(): void
    {
        $seen = null;
        Paginator::currentPageResolverUsing(function (string $name) use (&$seen) {
            $seen = $name;
            return 2;
        });

        Paginator::resolveCurrentPage('p');
        $this->assertSame('p', $seen);
    }

    public function test_null_resolver_value_falls_back_to_default(): void
    {
        Paginator::currentPageResolverUsing(fn(string $name) => null);
        $this->assertSame(1, Paginator::resolveCurrentPage());
    }

    public function test_non_numeric_value_falls_back_to_default(): void
    {
        Paginator::currentPageResolverUsing(fn(string $name) => 'not-a-number');
        $this->assertSame(1, Paginator::resolveCurrentPage());
    }

    public function test_zero_and_negative_fall_back_to_default(): void
    {
        Paginator::currentPageResolverUsing(fn(string $name) => '0');
        $this->assertSame(1, Paginator::resolveCurrentPage());

        Paginator::currentPageResolverUsing(fn(string $name) => '-4');
        $this->assertSame(1, Paginator::resolveCurrentPage());
    }

    public function test_no_resolver_uses_default(): void
    {
        Paginator::currentPageResolverUsing(null);
        $this->assertSame(1, Paginator::resolveCurrentPage());
        $this->assertSame(7, Paginator::resolveCurrentPage('page', 7));
    }
}
