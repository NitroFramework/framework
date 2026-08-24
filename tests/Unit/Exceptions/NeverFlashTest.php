<?php

namespace Tests\Unit\Exceptions;

use Nitro\Http\RedirectResponse;
use PHPUnit\Framework\TestCase;

/**
 * The never-flash list behind redirect()->withInput().
 *
 * The framework's password defaults were already correct; what was missing was
 * any way for an application to add its own secrets to them.
 */
class NeverFlashTest extends TestCase
{
    public function test_password_fields_are_never_flashed_by_default(): void
    {
        $flashed = RedirectResponse::neverFlashed();

        $this->assertContains('password', $flashed);
        $this->assertContains('password_confirmation', $flashed);
        $this->assertContains('current_password', $flashed);
    }

    public function test_an_application_can_add_its_own_secrets(): void
    {
        RedirectResponse::dontFlash(['api_token', 'card_number']);

        $flashed = RedirectResponse::neverFlashed();

        $this->assertContains('api_token', $flashed);
        $this->assertContains('card_number', $flashed);
        // The framework floor survives the addition.
        $this->assertContains('password', $flashed);
    }

    public function test_adding_the_same_field_twice_does_not_duplicate_it(): void
    {
        RedirectResponse::dontFlash(['ssn']);
        RedirectResponse::dontFlash(['ssn']);

        $this->assertSame(1, count(array_keys(RedirectResponse::neverFlashed(), 'ssn', true)));
    }
}
