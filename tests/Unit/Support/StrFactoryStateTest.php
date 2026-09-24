<?php

namespace Tests\Unit\Support;

use Nitro\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Taking over what Str generates.
 *
 * A test asserting on anything that embeds a generated value — a URL with a
 * token in it, a record keyed by UUID, a filename — cannot write the
 * expectation down while the value is random. Retrying until it passes is not
 * a test, and matching it with a regular expression asserts the shape rather
 * than the thing. So the value becomes the test's to choose.
 */
class StrFactoryStateTest extends TestCase
{
    /** Nothing may leak into the next test: the store is static. */
    protected function tearDown(): void
    {
        Str::resetFactoryState();

        parent::tearDown();
    }

    // ─── UUIDs ────────────────────────────────────────────

    public function test_uuids_can_be_supplied(): void
    {
        Str::createUuidsUsing(static fn (): string => 'fixed');

        $this->assertSame('fixed', Str::uuid());
        $this->assertSame('fixed', Str::uuid());
    }

    public function test_uuids_go_back_to_being_generated(): void
    {
        Str::createUuidsUsing(static fn (): string => 'fixed');
        Str::createUuidsNormally();

        $uuid = Str::uuid();

        $this->assertNotSame('fixed', $uuid);
        $this->assertTrue(Str::isUuid($uuid));
    }

    public function test_freezing_gives_the_same_uuid_every_time(): void
    {
        $frozen = Str::freezeUuids();

        $this->assertTrue(Str::isUuid($frozen));
        $this->assertSame($frozen, Str::uuid());
        $this->assertSame($frozen, Str::uuid());
    }

    /** The scoped form is the one to reach for: it cannot be left frozen. */
    public function test_freezing_for_a_callback_thaws_afterwards(): void
    {
        $seen = [];

        $frozen = Str::freezeUuids(function (string $uuid) use (&$seen): void {
            $seen = [$uuid, Str::uuid(), Str::uuid()];
        });

        $this->assertSame([$frozen, $frozen, $frozen], $seen);
        $this->assertNotSame($frozen, Str::uuid());
    }

    /**
     * A test failing inside the callback must still leave the generator as it
     * found it, or every later test fails somewhere unrelated.
     */
    public function test_freezing_thaws_even_when_the_callback_throws(): void
    {
        try {
            Str::freezeUuids(static function (): void {
                throw new RuntimeException('the assertion failed');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertTrue(Str::isUuid(Str::uuid()));
    }

    public function test_a_sequence_of_uuids_is_handed_out_in_order(): void
    {
        Str::createUuidsUsingSequence(['first', 'second']);

        $this->assertSame('first', Str::uuid());
        $this->assertSame('second', Str::uuid());
    }

    /** Past the end of the sequence, generating resumes. */
    public function test_a_sequence_falls_back_once_exhausted(): void
    {
        Str::createUuidsUsingSequence(['first']);

        $this->assertSame('first', Str::uuid());
        $this->assertTrue(Str::isUuid(Str::uuid()));
    }

    public function test_a_sequence_can_say_what_to_do_when_exhausted(): void
    {
        Str::createUuidsUsingSequence(['first'], static fn (): string => 'ran out');

        $this->assertSame('first', Str::uuid());
        $this->assertSame('ran out', Str::uuid());
    }

    // ─── ULIDs ────────────────────────────────────────────

    public function test_ulids_can_be_supplied_and_frozen(): void
    {
        Str::createUlidsUsing(static fn (): string => 'FIXEDULID');

        $this->assertSame('FIXEDULID', Str::ulid());

        Str::createUlidsNormally();

        $frozen = Str::freezeUlids();

        $this->assertSame(26, strlen($frozen));
        $this->assertSame($frozen, Str::ulid());
    }

    public function test_a_sequence_of_ulids_is_handed_out_in_order(): void
    {
        Str::createUlidsUsingSequence(['X', 'Y']);

        $this->assertSame('X', Str::ulid());
        $this->assertSame('Y', Str::ulid());
        $this->assertSame(26, strlen(Str::ulid()));
    }

    // ─── Random strings ───────────────────────────────────

    /** The factory is told the length, so it can honour it. */
    public function test_a_random_string_factory_is_given_the_length(): void
    {
        Str::createRandomStringsUsing(static fn (int $length): string => str_repeat('z', $length));

        $this->assertSame('zzzzz', Str::random(5));
        $this->assertSame('zz', Str::random(2));
    }

    public function test_a_random_string_factory_may_be_a_plain_string(): void
    {
        Str::createRandomStringsUsing('always this');

        $this->assertSame('always this', Str::random(5));
    }

    public function test_a_sequence_of_random_strings_is_handed_out_in_order(): void
    {
        Str::createRandomStringsUsingSequence(['one', 'two']);

        $this->assertSame('one', Str::random(4));
        $this->assertSame('two', Str::random(4));
        $this->assertSame(4, strlen(Str::random(4)));
    }

    // ─── All three at once ────────────────────────────────

    /** One call for a tearDown, so nothing is forgotten. */
    public function test_resetting_puts_every_generator_back(): void
    {
        Str::createUuidsUsing(static fn (): string => 'x');
        Str::createUlidsUsing(static fn (): string => 'y');
        Str::createRandomStringsUsing('z');

        Str::resetFactoryState();

        $this->assertTrue(Str::isUuid(Str::uuid()));
        $this->assertSame(26, strlen(Str::ulid()));
        $this->assertSame(8, strlen(Str::random(8)));
    }
}
