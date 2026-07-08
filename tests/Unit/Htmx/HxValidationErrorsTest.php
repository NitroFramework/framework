<?php

namespace Tests\Unit\Htmx;

use Nitro\Htmx\Support\HxValidationErrors;
use PHPUnit\Framework\TestCase;

/**
 * The read-side error bag returned by HxValidator. A thin wrapper over a
 * field => string[] map, but its accessors back every form-rendering path
 * (@error directives, first(), any()), so the contract is worth pinning.
 */
class HxValidationErrorsTest extends TestCase
{
    private function bag(): HxValidationErrors
    {
        return new HxValidationErrors([
            'email' => ['Email is required.', 'Email must be valid.'],
            'name'  => ['Name is required.'],
        ]);
    }

    public function test_empty_bag_reports_no_errors(): void
    {
        $bag = new HxValidationErrors();
        $this->assertFalse($bag->any());
        $this->assertTrue($bag->isEmpty());
        $this->assertSame(0, $bag->count());
        $this->assertSame([], $bag->all());
        $this->assertSame([], $bag->keys());
        $this->assertNull($bag->first('missing'));
        $this->assertFalse($bag->has('missing'));
        $this->assertSame([], $bag->get('missing'));
    }

    public function test_has_and_first(): void
    {
        $bag = $this->bag();
        $this->assertTrue($bag->has('email'));
        $this->assertFalse($bag->has('phone'));
        $this->assertSame('Email is required.', $bag->first('email'));
        $this->assertNull($bag->first('phone'));
    }

    public function test_get_returns_all_messages_for_a_field(): void
    {
        $this->assertSame(
            ['Email is required.', 'Email must be valid.'],
            $this->bag()->get('email'),
        );
    }

    public function test_any_isEmpty_and_count(): void
    {
        $bag = $this->bag();
        $this->assertTrue($bag->any());
        $this->assertFalse($bag->isEmpty());
        // count() flattens: 2 email + 1 name = 3 messages total.
        $this->assertSame(3, $bag->count());
    }

    public function test_all_flattens_every_message(): void
    {
        $this->assertSame(
            ['Email is required.', 'Email must be valid.', 'Name is required.'],
            $this->bag()->all(),
        );
    }

    public function test_keys_and_toArray_expose_structure(): void
    {
        $bag = $this->bag();
        $this->assertSame(['email', 'name'], $bag->keys());
        $this->assertSame(
            [
                'email' => ['Email is required.', 'Email must be valid.'],
                'name'  => ['Name is required.'],
            ],
            $bag->toArray(),
        );
    }
}
