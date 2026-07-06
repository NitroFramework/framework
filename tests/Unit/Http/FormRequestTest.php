<?php

namespace Tests\Unit\Http;

use Nitro\Container\Container;
use Nitro\Exceptions\HttpException;
use Nitro\Foundation\Providers\ValidationServiceProvider;
use Nitro\Http\FormRequest;
use Nitro\Http\Request;
use Nitro\Session\NativeSession;
use Nitro\Validation\ValidationException;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class StoreThingRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => 'required|email'];
    }
}

class UnauthorizedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return false;
    }

    public function rules(): array
    {
        return [];
    }
}

/**
 * FormRequest self-validates on construction: authorize() then rules(),
 * exposing validated() on success and throwing on failure.
 */
class FormRequestTest extends TestCase
{
    private function bind(array $body): NativeSession
    {
        Container::reset();
        $c = Container::getInstance();

        $session = new NativeSession('test_session');
        $session->start();
        $c->instance('session', $session);

        $request = new Request('POST', '/things', ['referer' => '/things'], [], $body);
        $c->instance('request', $request);
        $c->instance(Request::class, $request);

        (new ValidationServiceProvider($c))->boot();

        return $session;
    }

    protected function tearDown(): void
    {
        Container::reset();
    }

    #[RunInSeparateProcess]
    public function test_valid_request_exposes_validated_data(): void
    {
        $this->bind(['email' => 'a@b.c', 'extra' => 'ignored']);

        $request = new StoreThingRequest();

        $this->assertSame(['email' => 'a@b.c'], $request->validated());
    }

    #[RunInSeparateProcess]
    public function test_invalid_request_throws_validation_exception(): void
    {
        $this->bind(['email' => 'nope']);

        try {
            new StoreThingRequest();
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            // FormRequest throws a pure Validation-domain exception carrying the
            // errors. Converting it to a redirect-back (and flashing input +
            // errors) is the exception layer's job — covered in RequestValidateTest.
            $this->assertArrayHasKey('email', $e->errors()->all());
        }
    }

    #[RunInSeparateProcess]
    public function test_failed_authorize_throws_403(): void
    {
        $this->bind([]);

        try {
            new UnauthorizedRequest();
            $this->fail('Expected HttpException.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
