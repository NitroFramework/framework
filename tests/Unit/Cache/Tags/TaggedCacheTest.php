<?php

namespace Tests\Unit\Cache\Tags;

use Nitro\Cache\Drivers\ArrayStore;
use Nitro\Cache\Tags\TaggedCache;
use Nitro\Cache\Tags\TagSet;
use PHPUnit\Framework\TestCase;

class TaggedCacheTest extends TestCase
{
    protected ArrayStore $store;

    protected function setUp(): void
    {
        $this->store = new ArrayStore();
    }

    protected function tagged(array|string $tags): TaggedCache
    {
        $names = is_array($tags) ? $tags : [$tags];

        return new TaggedCache($this->store, new TagSet($this->store, $names));
    }

    // -------------------------------------------------------------------------
    // Basic Tag Operations
    // -------------------------------------------------------------------------

    public function test_it_can_store_and_retrieve_tagged_value(): void
    {
        $cache = $this->tagged('invoices');

        $cache->put('total', 1500, 3600);

        $this->assertSame(1500, $cache->get('total'));
    }

    public function test_tagged_values_are_isolated_by_tag(): void
    {
        $invoices = $this->tagged('invoices');
        $students = $this->tagged('students');

        $invoices->put('count', 50, 3600);
        $students->put('count', 200, 3600);

        $this->assertSame(50, $invoices->get('count'));
        $this->assertSame(200, $students->get('count'));
    }

    public function test_same_tag_same_namespace(): void
    {
        $first  = $this->tagged('invoices');
        $second = $this->tagged('invoices');

        $first->put('key', 'value', 3600);

        // Same tag name = same namespace = same data
        $this->assertSame('value', $second->get('key'));
    }

    // -------------------------------------------------------------------------
    // Multiple Tags
    // -------------------------------------------------------------------------

    public function test_multiple_tags_create_unique_namespace(): void
    {
        $both    = $this->tagged(['invoices', 'student:42']);
        $single  = $this->tagged('invoices');

        $both->put('data', 'both_tags', 3600);

        // Different tag set = different namespace = no collision
        $this->assertSame('both_tags', $both->get('data'));
        $this->assertNull($single->get('data'));
    }

    // -------------------------------------------------------------------------
    // Forever
    // -------------------------------------------------------------------------

    public function test_tagged_forever(): void
    {
        $cache = $this->tagged('permanent');

        $cache->forever('setting', 'always_on');

        $this->assertSame('always_on', $cache->get('setting'));
    }

    // -------------------------------------------------------------------------
    // Remember
    // -------------------------------------------------------------------------

    public function test_tagged_remember_on_miss(): void
    {
        $cache = $this->tagged('reports');

        $result = $cache->remember('monthly', 3600, fn() => ['total' => 999]);

        $this->assertSame(['total' => 999], $result);
    }

    public function test_tagged_remember_on_hit(): void
    {
        $cache = $this->tagged('reports');
        $cache->put('monthly', ['total' => 100], 3600);

        $called = false;
        $result = $cache->remember('monthly', 3600, function () use (&$called) {
            $called = true;
            return ['total' => 999];
        });

        $this->assertSame(['total' => 100], $result);
        $this->assertFalse($called);
    }

    // -------------------------------------------------------------------------
    // Forget
    // -------------------------------------------------------------------------

    public function test_forget_removes_single_tagged_item(): void
    {
        $cache = $this->tagged('invoices');

        $cache->put('a', 1, 3600);
        $cache->put('b', 2, 3600);

        $cache->forget('a');

        $this->assertNull($cache->get('a'));
        $this->assertSame(2, $cache->get('b'));
    }

    // -------------------------------------------------------------------------
    // Flush (Tag Invalidation)
    // -------------------------------------------------------------------------

    public function test_flush_invalidates_all_items_with_tag(): void
    {
        $cache = $this->tagged('invoices');

        $cache->put('a', 1, 3600);
        $cache->put('b', 2, 3600);
        $cache->put('c', 3, 3600);

        $cache->flush();

        // After flush, tag version changes, so old keys are inaccessible
        $this->assertNull($cache->get('a'));
        $this->assertNull($cache->get('b'));
        $this->assertNull($cache->get('c'));
    }

    public function test_flush_one_tag_does_not_affect_other_tags(): void
    {
        $invoices = $this->tagged('invoices');
        $students = $this->tagged('students');

        $invoices->put('inv', 'invoice_data', 3600);
        $students->put('stu', 'student_data', 3600);

        // Flush only invoices
        $invoices->flush();

        $this->assertNull($invoices->get('inv'));
        $this->assertSame('student_data', $students->get('stu'));
    }

    public function test_new_data_works_after_flush(): void
    {
        $cache = $this->tagged('invoices');

        $cache->put('key', 'old', 3600);
        $cache->flush();

        // Re-create tagged cache (new tag version)
        $cache = $this->tagged('invoices');
        $cache->put('key', 'new', 3600);

        $this->assertSame('new', $cache->get('key'));
    }

    // -------------------------------------------------------------------------
    // Increment / Decrement
    // -------------------------------------------------------------------------

    public function test_tagged_increment(): void
    {
        $cache = $this->tagged('counters');

        $this->assertSame(1, $cache->increment('hits'));
        $this->assertSame(6, $cache->increment('hits', 5));
    }

    public function test_tagged_decrement(): void
    {
        $cache = $this->tagged('counters');

        $cache->put('stock', 100, 3600);

        // Note: decrement on tagged keys works on the tagged namespace key
        $this->assertSame(97, $cache->decrement('stock', 3));
    }

    // -------------------------------------------------------------------------
    // TagSet Access
    // -------------------------------------------------------------------------

    public function test_get_tags_returns_tag_set(): void
    {
        $cache = $this->tagged(['invoices', 'students']);

        $tags = $cache->getTags();

        $this->assertInstanceOf(TagSet::class, $tags);
        $this->assertSame(['invoices', 'students'], $tags->getNames());
    }
}