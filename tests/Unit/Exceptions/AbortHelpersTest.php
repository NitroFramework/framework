<?php

namespace Tests\Unit\Exceptions;

use Nitro\Exceptions\HttpException;
use PHPUnit\Framework\TestCase;

/**
 * The one-line guard.
 *
 * The same thing as an if with an abort inside it, and worth having because the
 * long form invites the mistake: a guard written as a statement gets an early
 * return bolted on later, or a second branch, and the refusal quietly stops
 * covering the case it was written for.
 */
class AbortHelpersTest extends TestCase
{
    public function test_abort_if_refuses_when_the_condition_holds(): void
    {
        $this->expectException(HttpException::class);

        abort_if(true, 404);
    }

    public function test_abort_if_passes_when_it_does_not(): void
    {
        abort_if(false, 404);

        $this->assertTrue(true, 'nothing was thrown');
    }

    public function test_abort_unless_refuses_when_the_condition_fails(): void
    {
        $this->expectException(HttpException::class);

        abort_unless(false, 403);
    }

    public function test_abort_unless_passes_when_it_holds(): void
    {
        abort_unless(true, 403);

        $this->assertTrue(true, 'nothing was thrown');
    }

    public function test_the_status_and_message_survive(): void
    {
        try {
            abort_unless(false, 404, 'No such certificate.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('No such certificate.', $e->getMessage());

            return;
        }

        $this->fail('abort_unless should have thrown');
    }

    public function test_a_status_with_no_message_gets_its_standard_text(): void
    {
        try {
            abort_if(true, 419);
        } catch (HttpException $e) {
            $this->assertSame('Page Expired', $e->getMessage());

            return;
        }

        $this->fail('abort_if should have thrown');
    }

    public function test_headers_can_be_attached(): void
    {
        try {
            // A rate limit that does not say when to retry is a client
            // hammering the door.
            abort_if(true, 429, 'Slow down.', ['Retry-After' => '30']);
        } catch (HttpException $e) {
            $this->assertSame(['Retry-After' => '30'], $e->getHeaders());

            return;
        }

        $this->fail('abort_if should have thrown');
    }
}
