<?php

namespace Tests\Unit\Auth;

use Nitro\Auth\Concerns\RemembersUser;
use Nitro\Auth\Contracts\Authenticatable;
use Nitro\Auth\Contracts\UserProvider;
use Nitro\Auth\Events;
use Nitro\Auth\Recaller;
use Nitro\Auth\SessionGuard;
use Nitro\Container\Container;
use Nitro\Cookie\CookieJar;
use Nitro\Events\Dispatcher;
use Nitro\Session\ArraySessionHandler;
use Nitro\Session\Store;
use PHPUnit\Framework\TestCase;

/** Signing in for longer than the session lasts. */
class RememberMeTest extends TestCase
{
    private Store $session;
    private RememberProvider $provider;
    private CookieJar $cookies;
    private Dispatcher $events;

    protected function setUp(): void
    {
        Container::setInstance(new Container());

        $this->session = new Store('nitro_session', new ArraySessionHandler());
        $this->provider = new RememberProvider();
        $this->cookies = new CookieJar();
        $this->events = new Dispatcher();

        Container::getInstance()->instance('cookie', $this->cookies);
    }

    protected function tearDown(): void
    {
        Container::setInstance(new Container());
    }

    private function guard(): SessionGuard
    {
        return new SessionGuard($this->provider, $this->session, $this->events);
    }

    /** The cookie the guard queued, if it queued one. */
    private function queuedRecaller(SessionGuard $guard): ?string
    {
        $cookie = $this->cookies->queued($guard->getRecallerName());

        return $cookie?->value;
    }

    /** Arrive with the given cookie on the request. */
    private function arriveWith(string $name, string $value): void
    {
        Container::getInstance()->instance('request', new RequestWithCookies([$name => $value]));
    }

    // ── Issuing the cookie ────────────────────────────────────────────

    public function test_a_plain_login_queues_no_cookie(): void
    {
        $guard = $this->guard();

        $guard->login($this->provider->add(new RememberableUser(1)));

        $this->assertNull($this->queuedRecaller($guard));
    }

    /**
     * Remembering issues a cookie of three parts.
     *
     * The identifier says who, the token is revocable, and the password
     * hash makes the cookie stop working when the password changes.
     */
    public function test_remembering_queues_a_three_part_cookie(): void
    {
        $guard = $this->guard();

        $guard->login($this->provider->add(new RememberableUser(1)), true);

        $recaller = new Recaller($this->queuedRecaller($guard));

        $this->assertTrue($recaller->valid());
        $this->assertSame('1', (string) $recaller->id());
        $this->assertNotSame('', $recaller->token());
        $this->assertNotSame('', $recaller->hash());
    }

    /** A user with no token gets one when they are first remembered. */
    public function test_a_token_is_minted_on_the_first_remembered_login(): void
    {
        $user = $this->provider->add(new RememberableUser(1));

        $this->assertNull($user->getRememberToken());

        $this->guard()->login($user, true);

        $this->assertNotNull($user->getRememberToken());
    }

    public function test_an_existing_token_is_kept(): void
    {
        $user = $this->provider->add(new RememberableUser(1));
        $user->setRememberToken('already-issued');

        $this->guard()->login($user, true);

        $this->assertSame('already-issued', $user->getRememberToken());
    }

    // ── Coming back with it ───────────────────────────────────────────

    /**
     * A cookie signs the user back in when the session is gone.
     *
     * This is the whole feature: the session cookie expired, this one
     * did not, and the user is not asked to sign in again.
     */
    public function test_a_valid_cookie_signs_the_user_back_in(): void
    {
        $issuing = $this->guard();
        $user = $this->provider->add(new RememberableUser(1));
        $issuing->login($user, true);

        $cookie = $this->queuedRecaller($issuing);

        // A new request, with no session.
        $this->session = new Store('nitro_session', new ArraySessionHandler());
        $this->arriveWith($issuing->getRecallerName(), $cookie);

        $guard = $this->guard();

        $this->assertSame($user->getAuthIdentifier(), $guard->user()?->getAuthIdentifier());
        $this->assertTrue($guard->viaRemember());
    }

    /** And the session is written, so the next request needs no cookie. */
    public function test_the_session_is_established_from_the_cookie(): void
    {
        $issuing = $this->guard();
        $issuing->login($this->provider->add(new RememberableUser(1)), true);
        $cookie = $this->queuedRecaller($issuing);

        $this->session = new Store('nitro_session', new ArraySessionHandler());
        $this->arriveWith($issuing->getRecallerName(), $cookie);

        $this->guard()->user();

        $this->assertSame(1, $this->session->get('_auth_id'));
    }

    public function test_a_session_is_used_in_preference_to_the_cookie(): void
    {
        $user = $this->provider->add(new RememberableUser(1));

        $this->session->put('_auth_id', 1);

        $guard = $this->guard();

        $this->assertSame($user->getAuthIdentifier(), $guard->user()?->getAuthIdentifier());
        $this->assertFalse($guard->viaRemember(), 'the cookie was never consulted');
    }

    // ── Refusing a bad one ────────────────────────────────────────────

    public function test_a_cookie_with_the_wrong_token_is_refused(): void
    {
        $user = $this->provider->add(new RememberableUser(1));
        $user->setRememberToken('the-real-one');

        $this->arriveWith(
            $this->guard()->getRecallerName(),
            '1|a-forgery|' . hash('sha256', $user->getAuthPassword()),
        );

        $this->assertNull($this->guard()->user());
    }

    /**
     * Changing the password invalidates every remembered device.
     *
     * The cookie carries the hash it was issued against, so one held on
     * a device the session cannot reach stops working too.
     */
    public function test_a_cookie_stops_working_when_the_password_changes(): void
    {
        $issuing = $this->guard();
        $user = $this->provider->add(new RememberableUser(1));
        $issuing->login($user, true);
        $cookie = $this->queuedRecaller($issuing);

        $user->password = password_hash('a-new-password', PASSWORD_DEFAULT);

        $this->session = new Store('nitro_session', new ArraySessionHandler());
        $this->arriveWith($issuing->getRecallerName(), $cookie);

        $this->assertNull($this->guard()->user());
    }

    public function test_a_malformed_cookie_is_refused(): void
    {
        $this->provider->add(new RememberableUser(1));

        $this->arriveWith($this->guard()->getRecallerName(), 'nonsense');

        $this->assertNull($this->guard()->user());
    }

    // ── Signing out ───────────────────────────────────────────────────

    /**
     * Signing out revokes the cookie everywhere.
     *
     * Cycling the token is what reaches a device this session cannot.
     */
    public function test_logging_out_cycles_the_token(): void
    {
        $guard = $this->guard();
        $user = $this->provider->add(new RememberableUser(1));
        $guard->login($user, true);

        $issued = $user->getRememberToken();

        $guard->logout();

        $this->assertNotSame($issued, $user->getRememberToken());
    }

    /** Signing out of this device alone leaves the others working. */
    public function test_logging_out_this_device_keeps_the_token(): void
    {
        $guard = $this->guard();
        $user = $this->provider->add(new RememberableUser(1));
        $guard->login($user, true);

        $issued = $user->getRememberToken();

        $guard->logoutCurrentDevice();

        $this->assertSame($issued, $user->getRememberToken());
        $this->assertNull($guard->id());
    }

    /** And signing out the others asks for the password first. */
    public function test_logging_out_other_devices_needs_the_password(): void
    {
        $guard = $this->guard();
        $user = $this->provider->add(new RememberableUser(1));
        $guard->login($user, true);

        $issued = $user->getRememberToken();

        $this->assertNull($guard->logoutOtherDevices('wrong'));
        $this->assertSame($issued, $user->getRememberToken());

        $this->assertNotNull($guard->logoutOtherDevices('secret'));
        $this->assertNotSame($issued, $user->getRememberToken());
    }

    // ── Events ────────────────────────────────────────────────────────

    /** @var array<int, object> */
    private array $heard = [];

    private function record(string $event): void
    {
        $this->events->listen($event, function (object $e): void {
            $this->heard[] = $e;
        });
    }

    private function heardOf(string $type): ?object
    {
        foreach ($this->heard as $event) {
            if ($event instanceof $type) {
                return $event;
            }
        }

        return null;
    }

    public function test_a_login_says_whether_it_was_remembered(): void
    {
        $this->record(Events\Login::class);

        $this->guard()->login($this->provider->add(new RememberableUser(1)), true);

        $login = $this->heardOf(Events\Login::class);

        $this->assertNotNull($login);
        $this->assertTrue($login->remember);
    }

    public function test_a_failed_attempt_is_announced(): void
    {
        $this->record(Events\Failed::class);
        $this->record(Events\Attempting::class);

        $this->assertFalse($this->guard()->attempt(['email' => 'nobody@example.com', 'password' => 'x']));

        $this->assertNotNull($this->heardOf(Events\Attempting::class));
        $this->assertNotNull($this->heardOf(Events\Failed::class));
    }

    public function test_a_successful_attempt_is_announced(): void
    {
        $this->record(Events\Validated::class);
        $this->record(Events\Login::class);

        $this->provider->add(new RememberableUser(1));
        $this->provider->matching = 1;

        $this->assertTrue($this->guard()->attempt(['password' => 'secret']));

        $this->assertNotNull($this->heardOf(Events\Validated::class));
        $this->assertNotNull($this->heardOf(Events\Login::class));
    }

    public function test_logging_out_is_announced(): void
    {
        $this->record(Events\Logout::class);

        $guard = $this->guard();
        $guard->login($this->provider->add(new RememberableUser(1)));
        $guard->logout();

        $this->assertNotNull($this->heardOf(Events\Logout::class));
    }

    // ── Stateless checks ──────────────────────────────────────────────

    /** once() sets the user without writing a session. */
    public function test_once_authenticates_without_a_session(): void
    {
        $this->provider->add(new RememberableUser(1));
        $this->provider->matching = 1;

        $guard = $this->guard();

        $this->assertTrue($guard->once(['password' => 'secret']));
        $this->assertNotNull($guard->user());
        $this->assertNull($this->session->get('_auth_id'), 'no session was written');
    }

    public function test_once_using_id_does_the_same(): void
    {
        $this->provider->add(new RememberableUser(1));

        $guard = $this->guard();

        $this->assertNotNull($guard->onceUsingId(1));
        $this->assertNull($this->session->get('_auth_id'));
    }

    public function test_the_last_attempted_user_is_remembered(): void
    {
        $user = $this->provider->add(new RememberableUser(1));
        $this->provider->matching = 1;

        $guard = $this->guard();
        $guard->attempt(['password' => 'wrong']);

        $this->assertSame($user, $guard->getLastAttempted());
    }
}

// ── Doubles ───────────────────────────────────────────────────────────

class RememberableUser implements Authenticatable
{
    use RemembersUser;

    public string $password;

    public function __construct(public int $id, string $plain = 'secret')
    {
        $this->password = password_hash($plain, PASSWORD_DEFAULT);
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return $this->password;
    }
}

class RememberProvider implements UserProvider
{
    /** @var array<int|string, RememberableUser> */
    public array $users = [];

    /** The identifier retrieveByCredentials answers with, if any. */
    public int|string|null $matching = null;

    public function add(RememberableUser $user): RememberableUser
    {
        return $this->users[$user->getAuthIdentifier()] = $user;
    }

    public function retrieveById(mixed $identifier): ?Authenticatable
    {
        return $this->users[$identifier] ?? null;
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        return $this->matching === null ? null : ($this->users[$this->matching] ?? null);
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return password_verify((string) ($credentials['password'] ?? ''), $user->getAuthPassword());
    }

    public function retrieveByToken(mixed $identifier, string $token): ?Authenticatable
    {
        $user = $this->retrieveById($identifier);

        return $user !== null && $user->getRememberToken() === $token ? $user : null;
    }

    public function updateRememberToken(Authenticatable $user, ?string $token): void
    {
        $user->setRememberToken($token);
    }
}

/** Just enough request to carry cookies. */
class RequestWithCookies
{
    /** @param array<string, string> $cookies */
    public function __construct(private array $cookies) {}

    public function cookie(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->cookies : ($this->cookies[$key] ?? $default);
    }
}
