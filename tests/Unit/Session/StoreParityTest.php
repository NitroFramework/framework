<?php

namespace Tests\Unit\Session;

use Nitro\Session\ArraySessionHandler;
use Nitro\Session\DatabaseSessionHandler;
use Nitro\Session\Store;
use PHPUnit\Framework\TestCase;

/**
 * The accessors an application reaches for beyond put/get.
 *
 * Subset reads, old input, the previous URL and the handler seam all have
 * callers in auth, validation and redirects, so each is pinned here.
 */
class StoreParityTest extends TestCase
{
    private function store(): Store
    {
        return new Store('nitro_session', new ArraySessionHandler());
    }

    // ─── Subset reads ─────────────────────────────────────────────────────

    public function test_only_returns_just_the_named_keys(): void
    {
        $session = $this->store();
        $session->put(['a' => 1, 'b' => 2, 'c' => 3]);

        $this->assertSame(['a' => 1, 'c' => 3], $session->only(['a', 'c']));
    }

    public function test_only_ignores_keys_that_are_absent(): void
    {
        $session = $this->store();
        $session->put(['a' => 1]);

        $this->assertSame(['a' => 1], $session->only(['a', 'nope']));
    }

    public function test_except_returns_everything_else(): void
    {
        $session = $this->store();
        $session->put(['a' => 1, 'b' => 2, 'c' => 3]);

        $this->assertSame(['a' => 1, 'c' => 3], $session->except(['b']));
    }

    // ─── Presence ─────────────────────────────────────────────────────────

    public function test_missing_is_the_inverse_of_exists(): void
    {
        $session = $this->store();
        $session->put('nullable', null);

        $this->assertFalse($session->missing('nullable'));
        $this->assertTrue($session->missing('absent'));
    }

    public function test_has_any_is_true_when_one_key_is_set(): void
    {
        $session = $this->store();
        $session->put('b', 'x');

        $this->assertTrue($session->hasAny(['a', 'b']));
        $this->assertFalse($session->hasAny(['a', 'c']));
    }

    /** has() treats null as absent, so hasAny() must agree. */
    public function test_has_any_treats_a_null_value_as_absent(): void
    {
        $session = $this->store();
        $session->put('a', null);

        $this->assertFalse($session->hasAny(['a']));
    }

    // ─── Writing ──────────────────────────────────────────────────────────

    public function test_replace_puts_every_pair(): void
    {
        $session = $this->store();
        $session->put('a', 1);
        $session->replace(['b' => 2, 'c' => 3]);

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $session->all());
    }

    public function test_remove_returns_the_value_and_drops_the_key(): void
    {
        $session = $this->store();
        $session->put('a', 'gone');

        $this->assertSame('gone', $session->remove('a'));
        $this->assertFalse($session->exists('a'));
    }

    // ─── Old input ────────────────────────────────────────────────────────

    public function test_flashed_input_is_readable_by_key(): void
    {
        $session = $this->store();
        $session->flashInput(['email' => 'ada@example.com']);

        $this->assertSame('ada@example.com', $session->getOldInput('email'));
        $this->assertSame(['email' => 'ada@example.com'], $session->getOldInput());
    }

    public function test_old_input_falls_back_to_the_default(): void
    {
        $session = $this->store();
        $session->flashInput(['a' => 1]);

        $this->assertSame('fallback', $session->getOldInput('nope', 'fallback'));
    }

    public function test_has_old_input_reports_per_key_and_overall(): void
    {
        $session = $this->store();

        $this->assertFalse($session->hasOldInput());

        $session->flashInput(['email' => 'ada@example.com']);

        $this->assertTrue($session->hasOldInput());
        $this->assertTrue($session->hasOldInput('email'));
        $this->assertFalse($session->hasOldInput('name'));
    }

    /** Flashed input must survive exactly one request, like any other flash. */
    public function test_old_input_ages_out_after_one_request(): void
    {
        $session = $this->store();
        $session->flashInput(['email' => 'ada@example.com']);

        $session->ageFlashData();
        $this->assertTrue($session->hasOldInput('email'));

        $session->ageFlashData();
        $this->assertFalse($session->hasOldInput('email'));
    }

    // ─── Previous request ─────────────────────────────────────────────────

    public function test_the_previous_url_round_trips(): void
    {
        $session = $this->store();

        $this->assertNull($session->previousUrl());
        $this->assertFalse($session->hasPreviousUri());

        $session->setPreviousUrl('/orders/12');

        $this->assertSame('/orders/12', $session->previousUrl());
        $this->assertTrue($session->hasPreviousUri());
    }

    public function test_the_previous_route_round_trips(): void
    {
        $session = $this->store();
        $session->setPreviousRoute('orders.show');

        $this->assertSame('orders.show', $session->previousRoute());
    }

    public function test_password_confirmed_records_a_timestamp(): void
    {
        $session = $this->store();
        $session->passwordConfirmed();

        $this->assertEqualsWithDelta(time(), $session->get('auth.password_confirmed_at'), 2);
    }

    // ─── Identity ─────────────────────────────────────────────────────────

    public function test_id_is_an_alias_of_get_id(): void
    {
        $session = $this->store();

        $this->assertSame($session->getId(), $session->id());
    }

    public function test_is_valid_id_accepts_only_forty_alphanumeric_characters(): void
    {
        $session = $this->store();

        $this->assertTrue($session->isValidId(str_repeat('a', 40)));
        $this->assertFalse($session->isValidId(str_repeat('a', 39)));
        $this->assertFalse($session->isValidId(str_repeat('-', 40)));
        $this->assertFalse($session->isValidId(null));
    }

    // ─── Handler seam ─────────────────────────────────────────────────────

    public function test_set_handler_swaps_the_backing_handler(): void
    {
        $session = $this->store();
        $replacement = new ArraySessionHandler();

        $session->setHandler($replacement);

        $this->assertSame($replacement, $session->getHandler());
    }

    /** Only the cookie handler reads the request, so nothing else is handed one. */
    public function test_a_plain_handler_does_not_need_the_request(): void
    {
        $session = $this->store();

        $this->assertFalse($session->handlerNeedsRequest());
        $session->setRequestOnHandler(null);
    }

    public function test_set_exists_reaches_an_existence_aware_handler(): void
    {
        $handler = new DatabaseSessionHandler('sessions', 120);
        $session = new Store('nitro_session', $handler);

        $session->setExists(true);

        $reflection = new \ReflectionProperty($handler, 'exists');
        $this->assertTrue($reflection->getValue($handler));
    }

    /** A handler that is not existence-aware must simply be left alone. */
    public function test_set_exists_is_a_no_op_for_other_handlers(): void
    {
        $session = $this->store();

        $session->setExists(true);

        $this->assertInstanceOf(ArraySessionHandler::class, $session->getHandler());
    }
}
