<?php

namespace Tests\Unit\Cache;

use InvalidArgumentException;
use Nitro\Cache\Drivers\ArrayStore;
use Nitro\Cache\Repository;
use PHPUnit\Framework\TestCase;

/** Reads that state what they expect, and the interop names. */
class RepositoryParityTest extends TestCase
{
    private function cache(): Repository
    {
        return new Repository(new ArrayStore());
    }

    // --- typed reads --------------------------------------------------------

    public function test_an_integer_is_returned_as_one(): void
    {
        $cache = $this->cache();
        $cache->put('count', 5, 60);

        $this->assertSame(5, $cache->integer('count'));
    }

    /** A numeric string is a valid integer; anything else is not. */
    public function test_a_numeric_string_is_accepted_as_an_integer(): void
    {
        $cache = $this->cache();
        $cache->put('count', '5', 60);

        $this->assertSame(5, $cache->integer('count'));
    }

    public function test_a_non_integer_is_refused_by_name(): void
    {
        $cache = $this->cache();
        $cache->put('count', 'many', 60);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/count/');

        $cache->integer('count');
    }

    public function test_the_other_typed_reads(): void
    {
        $cache = $this->cache();

        $cache->put('ratio', 1.5, 60);
        $cache->put('name', 'ada', 60);
        $cache->put('on', true, 60);
        $cache->put('rows', [1, 2], 60);

        $this->assertSame(1.5, $cache->float('ratio'));
        $this->assertSame('ada', $cache->string('name'));
        $this->assertTrue($cache->boolean('on'));
        $this->assertSame([1, 2], $cache->array('rows'));
    }

    public function test_a_wrong_type_is_refused_for_each(): void
    {
        $cache = $this->cache();
        $cache->put('name', ['not', 'a', 'string'], 60);

        $this->expectException(InvalidArgumentException::class);

        $cache->string('name');
    }

    // --- small additions ----------------------------------------------------

    public function test_missing_is_the_inverse_of_has(): void
    {
        $cache = $this->cache();
        $cache->put('here', 1, 60);

        $this->assertFalse($cache->missing('here'));
        $this->assertTrue($cache->missing('gone'));
    }

    /** Extending a lifetime keeps the value it already had. */
    public function test_touch_extends_an_entry_without_rebuilding_it(): void
    {
        $cache = $this->cache();
        $cache->put('report', 'built', 1);

        $this->assertTrue($cache->touch('report', 600));
        $this->assertSame('built', $cache->get('report'));
    }

    /** Asking for no lifetime is asking for it to be gone. */
    public function test_touching_with_no_lifetime_forgets_the_entry(): void
    {
        $cache = $this->cache();
        $cache->put('report', 'built', 60);

        $cache->touch('report', 0);

        $this->assertNull($cache->get('report'));
    }

    public function test_touching_something_absent_reports_failure(): void
    {
        $this->assertFalse($this->cache()->touch('never-stored', 60));
    }

    public function test_sear_stores_forever(): void
    {
        $cache = $this->cache();

        $this->assertSame('v', $cache->sear('key', static fn (): string => 'v'));
        $this->assertSame('v', $cache->get('key'));
    }

    public function test_tag_support_is_reported(): void
    {
        $this->assertFalse($this->cache()->supportsTags());
    }

    // --- PSR-16 names -------------------------------------------------------

    public function test_the_interop_names_do_the_same_thing(): void
    {
        $cache = $this->cache();

        $cache->set('a', 1, 60);

        $this->assertSame(1, $cache->get('a'));
        $this->assertTrue($cache->delete('a'));
        $this->assertNull($cache->get('a'));
    }

    public function test_many_keys_at_once(): void
    {
        $cache = $this->cache();

        $cache->setMultiple(['a' => 1, 'b' => 2], 60);

        $this->assertSame(['a' => 1, 'b' => 2], $cache->getMultiple(['a', 'b']));

        $cache->deleteMultiple(['a', 'b']);

        $this->assertSame(['a' => null, 'b' => null], $cache->getMultiple(['a', 'b']));
    }

    /** A key that was never stored comes back as the default, not as missing. */
    public function test_absent_keys_take_the_default(): void
    {
        $cache = $this->cache();
        $cache->put('a', 1, 60);

        $this->assertSame(['a' => 1, 'b' => 'fallback'], $cache->getMultiple(['a', 'b'], 'fallback'));
    }

    public function test_clear_empties_the_store(): void
    {
        $cache = $this->cache();
        $cache->put('a', 1, 60);

        $this->assertTrue($cache->clear());
        $this->assertNull($cache->get('a'));
    }

    /** Anything the contract does not name is asked of the driver. */
    public function test_unknown_calls_reach_the_store(): void
    {
        $cache = $this->cache();

        $this->assertSame('', $cache->getPrefix());
    }
}
