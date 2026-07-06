<?php

namespace Tests\Unit\Http;

use Nitro\Container\Container;
use Nitro\Exceptions\ExceptionHandler;
use Nitro\Foundation\Config;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\Providers\ExceptionServiceProvider;
use Nitro\Foundation\Providers\ValidationServiceProvider;
use Nitro\Http\RedirectResponse;
use Nitro\Http\Request;
use Nitro\Session\NativeSession;
use Nitro\Validation\ValidationException;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the Laravel-style request()->validate() macro: returns the validated
 * subset on success, and on failure throws a pure {@see ValidationException}. The
 * redirect-back / 422-JSON conversion is the exception-handling layer's job, so
 * the Validation layer stays free of any Http dependency — that conversion is
 * exercised here through ExceptionHandler::renderResponse().
 */
class RequestValidateTest extends TestCase
{
    private function bootValidation(Request $request): NativeSession
    {
        Container::reset();
        $container = Container::getInstance();

        $session = new NativeSession('test_session');
        $session->start();
        $container->instance('session', $session);
        $container->instance('request', $request);
        $container->instance(Request::class, $request);

        (new ValidationServiceProvider($container))->boot();

        return $session;
    }

    /** Register the ValidationException → response converter, as the app does at boot. */
    private function bootExceptionConverter(): ExceptionHandler
    {
        $container = Container::getInstance();
        $config = Config::fromArray([]);
        $container->instance(ConfigRepository::class, $config);

        $handler = new ExceptionHandler($config, $container);
        $container->instance(ExceptionHandler::class, $handler);
        (new ExceptionServiceProvider($container))->boot($handler);

        return $handler;
    }

    protected function tearDown(): void
    {
        Container::reset();
    }

    #[RunInSeparateProcess]
    public function test_validate_returns_only_validated_fields_on_success(): void
    {
        $request = new Request('POST', '/login', [], [], [
            'email'    => 'a@b.c',
            'password' => 'secret',
            'extra'    => 'ignored',
        ]);
        $this->bootValidation($request);

        $validated = $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $this->assertSame(['email' => 'a@b.c', 'password' => 'secret'], $validated);
    }

    #[RunInSeparateProcess]
    public function test_validate_failure_throws_pure_exception_that_converts_to_redirect(): void
    {
        $request = new Request('POST', '/login', ['referer' => '/login'], [], [
            'email'    => 'not-an-email',
            'password' => '',
        ]);
        $session = $this->bootValidation($request);
        $handler = $this->bootExceptionConverter();

        try {
            $request->validate([
                'email'    => 'required|email',
                'password' => 'required',
            ]);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            // The macro throws a pure Validation-domain exception (no Http).
            $this->assertArrayHasKey('email', $e->errors()->all());

            // The exception layer converts it into a redirect-back response,
            // flashing input + errors in the process.
            $response = $handler->renderResponse($e, $request);
            $this->assertInstanceOf(RedirectResponse::class, $response);
            $this->assertSame('/login', $response->header('Location'));
        }

        $this->assertArrayHasKey('email', $session->get('errors'));
        $this->assertSame('not-an-email', $session->get('_old_input')['email']);
    }
}
