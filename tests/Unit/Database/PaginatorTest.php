<?php

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Nitro\Database\Query\Paginator;

class PaginatorTest extends TestCase
{
    private function make(int $total = 50, int $perPage = 10, int $page = 1): Paginator
    {
        $count = max(0, min($perPage, $total - ($page - 1) * $perPage));
        $items = $count > 0 ? range(1, $count) : [];
        return new Paginator($items, $total, $perPage, $page);
    }

    // =========================================================================
    // Basic Info
    // =========================================================================

    public function test_total(): void
    {
        $this->assertSame(50, $this->make(50)->total());
    }

    public function test_per_page(): void
    {
        $this->assertSame(10, $this->make(50, 10)->perPage());
    }

    public function test_current_page(): void
    {
        $this->assertSame(3, $this->make(50, 10, 3)->currentPage());
    }

    public function test_last_page(): void
    {
        $this->assertSame(5, $this->make(50, 10)->lastPage());
    }

    public function test_last_page_uneven(): void
    {
        $this->assertSame(6, $this->make(51, 10)->lastPage());
    }

    // =========================================================================
    // Navigation
    // =========================================================================

    public function test_has_more_pages_true(): void
    {
        $this->assertTrue($this->make(50, 10, 1)->hasMorePages());
    }

    public function test_has_more_pages_false_on_last(): void
    {
        $this->assertFalse($this->make(50, 10, 5)->hasMorePages());
    }

    public function test_has_pages_true(): void
    {
        $this->assertTrue($this->make(50, 10)->hasPages());
    }

    public function test_has_pages_false_single_page(): void
    {
        $this->assertFalse($this->make(5, 10)->hasPages());
    }

    // =========================================================================
    // Items
    // =========================================================================

    public function test_items_returns_array(): void
    {
        $this->assertIsArray($this->make(50, 10, 1)->items());
    }

    public function test_items_count(): void
    {
        $this->assertCount(10, $this->make(50, 10, 1)->items());
    }

    public function test_items_last_page_partial(): void
    {
        $this->assertCount(3, $this->make(23, 10, 3)->items());
    }

    public function test_first_item(): void
    {
        $this->assertSame(21, $this->make(50, 10, 3)->firstItem());
    }

    public function test_last_item(): void
    {
        $this->assertSame(30, $this->make(50, 10, 3)->lastItem());
    }

    public function test_first_item_page_one(): void
    {
        $this->assertSame(1, $this->make(50, 10, 1)->firstItem());
    }

    // =========================================================================
    // Countable & Iterable
    // =========================================================================

    public function test_countable(): void
    {
        $this->assertSame(10, count($this->make(50, 10, 1)));
    }

    public function test_iterable(): void
    {
        $count = 0;
        foreach ($this->make(5, 5, 1) as $item) {
            $count++;
        }
        $this->assertSame(5, $count);
    }

    // =========================================================================
    // Serialization
    // =========================================================================

    public function test_to_array_structure(): void
    {
        $arr = $this->make(50, 10, 2)->toArray();
        $this->assertArrayHasKey('data', $arr);
        $this->assertArrayHasKey('total', $arr);
        $this->assertArrayHasKey('per_page', $arr);
        $this->assertArrayHasKey('current_page', $arr);
        $this->assertArrayHasKey('last_page', $arr);
    }

    public function test_to_array_values(): void
    {
        $arr = $this->make(50, 10, 2)->toArray();
        $this->assertSame(50, $arr['total']);
        $this->assertSame(10, $arr['per_page']);
        $this->assertSame(2, $arr['current_page']);
        $this->assertSame(5, $arr['last_page']);
    }

    public function test_to_json(): void
    {
        $decoded = json_decode($this->make(10, 5, 1)->toJson(), true);
        $this->assertSame(10, $decoded['total']);
        $this->assertSame(5, $decoded['per_page']);
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function test_single_page(): void
    {
        $p = $this->make(3, 10, 1);
        $this->assertSame(1, $p->lastPage());
        $this->assertFalse($p->hasMorePages());
        $this->assertFalse($p->hasPages());
    }

    public function test_empty_results(): void
    {
        $p = $this->make(0, 10, 1);
        $this->assertSame(0, $p->total());
        $this->assertCount(0, $p->items());
        $this->assertFalse($p->hasMorePages());
    }

    public function test_exact_fit(): void
    {
        $p = $this->make(20, 10, 2);
        $this->assertSame(2, $p->lastPage());
        $this->assertFalse($p->hasMorePages());
    }
}