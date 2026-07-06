<?php

namespace Tests\Unit\Http;

use Nitro\Container\Container;
use Nitro\Http\RedirectResponse;
use Nitro\Http\Request;
use Nitro\Session\NativeSession;
use Nitro\Validation\ErrorBag;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Laravel-style fluent flash API end to end: the redirect builds
 * the right Location/status, and ->withInput()/->withErrors()/->with() land in
 * the real session where old()/errors() read them.
 */
class RedirectResponseTest extends TestCase
{
    private NativeSession $session;

    protected function setUp(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        Container::reset();
        $container = Container::getInstance();

        $this->session = new NativeSession('test_session');
        $this->session->start();
        $container->instance('session', $this->session);

        // Submitted form input — password must never be flashed back.
        $container->instance('request', new Request(
            'POST',
            '/login',
            [],
            [],
            ['email' => 'a@b.c', 'password' => 'secret', 'name' => 'Ada'],
        ));
    }

    protected function tearDown(): void
    {
        Container::reset();
    }

    #[RunInSeparateProcess]
    public function test_redirect_sets_location_and_status(): void
    {
        $response = new RedirectResponse('/login', 302);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->header('Location'));
    }

    #[RunInSeparateProcess]
    public function test_with_input_flashes_request_input_without_passwords(): void
    {
        (new RedirectResponse('/login'))->withInput();

        $old = $this->session->get('_old_input');
        $this->assertSame(['email' => 'a@b.c', 'name' => 'Ada'], $old);
        $this->assertArrayNotHasKey('password', $old);
    }

    #[RunInSeparateProcess]
    public function test_with_input_accepts_explicit_array(): void
    {
        (new RedirectResponse('/login'))->withInput(['only' => 'this']);

        $this->assertSame(['only' => 'this'], $this->session->get('_old_input'));
    }

    #[RunInSeparateProcess]
    public function test_with_errors_normalizes_flat_array(): void
    {
        (new RedirectResponse('/login'))->withErrors(['email' => 'Bad credentials.']);

        $this->assertSame(['email' => 'Bad credentials.'], $this->session->get('errors'));
    }

    #[RunInSeparateProcess]
    public function test_with_errors_normalizes_error_bag_to_first_message(): void
    {
        $bag = new ErrorBag();
        $bag->add('email', 'First.');
        $bag->add('email', 'Second.');
        $bag->add('name', 'Required.');

        (new RedirectResponse('/login'))->withErrors($bag);

        $this->assertSame(
            ['email' => 'First.', 'name' => 'Required.'],
            $this->session->get('errors'),
        );
    }

    #[RunInSeparateProcess]
    public function test_with_flashes_arbitrary_keys_and_is_chainable(): void
    {
        $response = (new RedirectResponse('/dashboard'))
            ->with('status', 'Saved')
            ->withErrors(['x' => 'y']);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('Saved', $this->session->get('status'));
        $this->assertSame(['x' => 'y'], $this->session->get('errors'));
    }
}
