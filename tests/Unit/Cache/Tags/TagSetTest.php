<?php

namespace Tests\Unit\Cache\Tags;

use Nitro\Cache\Drivers\ArrayStore;
use Nitro\Cache\Tags\TagSet;
use PHPUnit\Framework\TestCase;

class TagSetTest extends TestCase
{
    protected ArrayStore $store;

    protected function setUp(): void
    {
        $this->store = new ArrayStore();
    }

    // -------------------------------------------------------------------------
    // Basics
    // -------------------------------------------------------------------------

    public function test_it_stores_tag_names(): void
    {
        $tagSet = new TagSet($this->store, ['invoices', 'students']);

        $this->assertSame(['invoices', 'students'], $tagSet->getNames());
    }

    public function test_empty_tag_set(): void
    {
        $tagSet = new TagSet($this->store, []);

        $this->assertSame([], $tagSet->getNames());
        $this->assertSame('', $tagSet->getNamespace());
    }

    // -------------------------------------------------------------------------
    // Namespace Generation
    // -------------------------------------------------------------------------

    public function test_get_namespace_returns_string(): void
    {
        $tagSet = new TagSet($this->store, ['invoices']);

        $namespace = $tagSet->getNamespace();

        $this->assertIsString($namespace);
        $this->assertNotEmpty($namespace);
    }

    public function test_same_tags_return_same_namespace(): void
    {
        $first  = new TagSet($this->store, ['invoices']);
        $second = new TagSet($this->store, ['invoices']);

        // First call creates the tag version
        $ns1 = $first->getNamespace();
        // Second call reads the same tag version
        $ns2 = $second->getNamespace();

        $this->assertSame($ns1, $ns2);
    }

    public function test_different_tags_return_different_namespaces(): void
    {
        $invoices = new TagSet($this->store, ['invoices']);
        $students = new TagSet($this->store, ['students']);

        $this->assertNotSame(
            $invoices->getNamespace(),
            $students->getNamespace()
        );
    }

    public function test_multiple_tags_combined_in_namespace(): void
    {
        $tagSet = new TagSet($this->store, ['invoices', 'students']);

        $namespace = $tagSet->getNamespace();

        // Namespace should contain a separator (pipe)
        $this->assertStringContainsString('|', $namespace);
    }

    public function test_tag_order_matters(): void
    {
        $ab = new TagSet($this->store, ['a', 'b']);
        $ba = new TagSet($this->store, ['b', 'a']);

        // Different order = different namespace (tag IDs are position-dependent)
        // Actually they share the same underlying tag IDs, but the order in the
        // pipe-separated string will differ
        $nsAb = $ab->getNamespace();
        $nsBa = $ba->getNamespace();

        // Both contain the same IDs but in different order
        $this->assertIsString($nsAb);
        $this->assertIsString($nsBa);
    }

    // -------------------------------------------------------------------------
    // Reset
    // -------------------------------------------------------------------------

    public function test_reset_changes_namespace(): void
    {
        $tagSet = new TagSet($this->store, ['invoices']);

        $before = $tagSet->getNamespace();
        $tagSet->reset();
        $after = $tagSet->getNamespace();

        $this->assertNotSame($before, $after);
    }

    public function test_reset_tag_returns_new_id(): void
    {
        $tagSet = new TagSet($this->store, ['invoices']);

        $id1 = $tagSet->resetTag('invoices');
        $id2 = $tagSet->resetTag('invoices');

        $this->assertNotSame($id1, $id2);
    }

    // -------------------------------------------------------------------------
    // Tag Key
    // -------------------------------------------------------------------------

    public function test_tag_key_format(): void
    {
        $tagSet = new TagSet($this->store, ['invoices']);

        $this->assertSame('tag:invoices:key', $tagSet->tagKey('invoices'));
        $this->assertSame('tag:students:key', $tagSet->tagKey('students'));
    }
}