<?php

namespace Tests\Unit\Http;

use Nitro\Container\Container;
use Nitro\Http\Controller\Concerns\PerformsValidation;
use Nitro\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * validate() with no data reads the current request.
 *
 * That default is the documented way to use the trait and the way every
 * controller calls it, and it was broken: the implementation asked the
 * `input()` helper for everything, but that helper takes a key and returns
 * one value, so the bare call was an argument error. Nothing caught it
 * because the failure only happens at request time, with a request bound.
 */
class PerformsValidationTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();

        Container::setInstance($this->container);
    }

    protected function tearDown(): void
    {
        Container::reset();
    }

    private function bindRequest(array $body): void
    {
        $request = new Request('POST', '/students');

        $reflection = new \ReflectionProperty(Request::class, 'body');
        $reflection->setValue($request, $body);

        $this->container->instance('request', $request);
        $this->container->instance(Request::class, $request);
    }

    /** A controller-shaped holder for the trait. */
    private function controller(): object
    {
        return new class {
            use PerformsValidation {
                validate as public runValidation;
            }
        };
    }

    public function test_validate_defaults_to_the_current_request(): void
    {
        $this->bindRequest(['first_name' => 'Ada', 'email' => 'ada@example.com']);

        $errors = $this->controller()->runValidation([
            'first_name' => 'required|string',
            'email'      => 'required|email',
        ]);

        $this->assertSame([], $errors, 'valid request input must produce no errors');
    }

    public function test_validate_reports_one_message_per_failing_field(): void
    {
        $this->bindRequest(['email' => 'not-an-email']);

        $errors = $this->controller()->runValidation([
            'first_name' => 'required|string',
            'email'      => 'required|email',
        ]);

        $this->assertArrayHasKey('first_name', $errors);
        $this->assertArrayHasKey('email', $errors);
        $this->assertIsString($errors['email']);
    }

    /** Passing data explicitly still bypasses the request. */
    public function test_explicit_data_is_used_in_place_of_the_request(): void
    {
        $this->bindRequest(['first_name' => 'ignored']);

        $errors = $this->controller()->runValidation(
            ['first_name' => 'required|string'],
            ['first_name' => 'Grace']
        );

        $this->assertSame([], $errors);
    }
}
