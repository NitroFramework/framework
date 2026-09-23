<?php

namespace Tests\Unit\Auth;

use Nitro\Auth\Access\Gate;
use Nitro\Auth\Access\HandlesAuthorization;
use Nitro\Auth\Access\Response;
use Nitro\Auth\AuthManager;
use Nitro\Auth\Concerns\RemembersUser;
use Nitro\Auth\Contracts\Authenticatable;
use Nitro\Auth\Contracts\Guard;
use Nitro\Auth\Contracts\StatefulGuard;
use Nitro\Auth\Contracts\UserProvider;
use Nitro\Auth\Exceptions\AuthorizationException;
use Nitro\Auth\GenericUser;
use Nitro\Auth\RequestGuard;
use Nitro\Auth\SessionGuard;
use Nitro\Auth\TokenGuard;
use Nitro\Container\Container;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Foundation\Config;
use Nitro\Session\ArraySessionHandler;
use Nitro\Session\Store;
use PHPUnit\Framework\TestCase;

/** More than one way to be signed in, and deciding what you may do. */
class AuthManagerTest extends TestCase
{
    protected function setUp(): void
    {
        Container::setInstance(new Container());
    }

    protected function tearDown(): void
    {
        Container::setInstance(new Container());
    }

    /** @param array<string, mixed> $auth */
    private function manager(array $auth = []): AuthManager
    {
        $config = Config::fromArray(['auth' => $auth]);

        Container::getInstance()->instance(Config::class, $config);
        Container::getInstance()->instance(UserProvider::class, new ManagerProvider());

        return new AuthManager(
            config: $config,
            resolver: Container::getInstance()->resolve(ClassResolver::class),
            sessionResolver: static fn (): Store => new Store('nitro_session', new ArraySessionHandler()),
        );
    }

    // ── Guards by name ────────────────────────────────────────────────

    /** With nothing configured, the default guard is a session one. */
    public function test_the_default_guard_is_a_session_guard(): void
    {
        $this->assertInstanceOf(SessionGuard::class, $this->manager()->guard());
    }

    public function test_a_named_guard_is_built_from_its_driver(): void
    {
        $manager = $this->manager([
            'defaults' => ['guard' => 'web'],
            'guards' => [
                'web' => ['driver' => 'session'],
                'api' => ['driver' => 'token'],
            ],
        ]);

        $this->assertInstanceOf(SessionGuard::class, $manager->guard('web'));
        $this->assertInstanceOf(TokenGuard::class, $manager->guard('api'));
    }

    public function test_a_guard_is_built_once(): void
    {
        $manager = $this->manager();

        $this->assertSame($manager->guard(), $manager->guard());
    }

    public function test_an_unknown_guard_is_refused_by_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/carrier-pigeon/');

        $this->manager()->guard('carrier-pigeon');
    }

    /** Calls the manager does not name go to the default guard. */
    public function test_unnamed_calls_reach_the_default_guard(): void
    {
        $manager = $this->manager();

        $this->assertFalse($manager->check());
        $this->assertNull($manager->user());
    }

    public function test_the_default_can_be_changed_for_the_request(): void
    {
        $manager = $this->manager([
            'defaults' => ['guard' => 'web'],
            'guards' => ['web' => ['driver' => 'session'], 'api' => ['driver' => 'token']],
        ]);

        $manager->shouldUse('api');

        $this->assertInstanceOf(TokenGuard::class, $manager->guard());
    }

    public function test_a_guard_of_your_own_can_be_added(): void
    {
        $manager = $this->manager(['guards' => ['web' => ['driver' => 'smoke-signal']]]);

        $manager->extend('smoke-signal', static fn (): Guard => new TokenGuard(new ManagerProvider()));

        $this->assertInstanceOf(TokenGuard::class, $manager->guard('web'));
    }

    /** A callback is enough to authenticate, for a scheme nothing models. */
    public function test_a_guard_can_be_a_callback(): void
    {
        $user = new ManagerUser(1);

        $manager = $this->manager(['guards' => ['web' => ['driver' => 'headers']]]);
        $manager->viaRequest('headers', static fn (): Authenticatable => $user);

        $guard = $manager->guard('web');

        $this->assertInstanceOf(RequestGuard::class, $guard);
        $this->assertSame($user, $guard->user());
    }

    public function test_a_provider_of_your_own_can_be_added(): void
    {
        $manager = $this->manager([
            'defaults' => ['guard' => 'web', 'provider' => 'ours'],
            'guards' => ['web' => ['driver' => 'session', 'provider' => 'ours']],
            'providers' => ['ours' => ['driver' => 'carrier-pigeon']],
        ]);

        $provider = new ManagerProvider();
        $manager->provider('carrier-pigeon', static fn (): UserProvider => $provider);

        $this->assertSame($provider, $manager->guard('web')->getProvider());
    }

    public function test_guards_can_be_dropped(): void
    {
        $manager = $this->manager();
        $first = $manager->guard();

        $manager->forgetGuards();

        $this->assertNotSame($first, $manager->guard());
    }

    // ── The token guard ───────────────────────────────────────────────

    public function test_a_token_guard_finds_a_user_by_bearer_token(): void
    {
        $provider = new ManagerProvider();
        $user = $provider->add(new ManagerUser(1, 'the-token'));

        $guard = new TokenGuard($provider, new TokenRequest('the-token'));

        $this->assertSame($user, $guard->user());
        $this->assertTrue($guard->check());
        $this->assertSame(1, $guard->id());
    }

    public function test_a_token_guard_without_a_token_has_no_user(): void
    {
        $guard = new TokenGuard(new ManagerProvider(), new TokenRequest(null));

        $this->assertNull($guard->user());
        $this->assertTrue($guard->guest());
    }

    /** A hashed column is compared against the digest, not the token. */
    public function test_a_token_guard_can_compare_against_a_digest(): void
    {
        $provider = new ManagerProvider();
        $user = $provider->add(new ManagerUser(1, hash('sha256', 'the-token')));

        $guard = new TokenGuard($provider, new TokenRequest('the-token'), hash: true);

        $this->assertSame($user, $guard->user());
    }

    public function test_a_resolved_user_is_not_looked_up_twice(): void
    {
        $provider = new ManagerProvider();
        $provider->add(new ManagerUser(1, 'the-token'));

        $guard = new TokenGuard($provider, new TokenRequest('the-token'));
        $guard->user();

        $this->assertTrue($guard->hasUser());
    }

    // ── A generic user ────────────────────────────────────────────────

    public function test_a_generic_user_answers_the_contract(): void
    {
        $user = new GenericUser(['id' => 7, 'password' => 'hash', 'remember_token' => 'tok']);

        $this->assertSame(7, $user->getAuthIdentifier());
        $this->assertSame('hash', $user->getAuthPassword());
        $this->assertSame('tok', $user->getRememberToken());
        $this->assertSame('id', $user->getAuthIdentifierName());
    }

    public function test_a_generic_user_reads_its_other_columns(): void
    {
        $user = new GenericUser(['id' => 7, 'email' => 'ada@example.com']);

        $this->assertSame('ada@example.com', $user->email);
        $this->assertTrue(isset($user->email));
        $this->assertNull($user->nothing);
    }

    // ── The gate's answers ────────────────────────────────────────────

    private function gate(mixed $user = null): Gate
    {
        return new Gate(
            Container::getInstance()->resolve(ClassResolver::class),
            static fn (): mixed => $user,
        );
    }

    public function test_an_ability_can_be_defined_and_checked(): void
    {
        $gate = $this->gate(new ManagerUser(1));
        $gate->define('edit', static fn (mixed $user): bool => $user !== null);

        $this->assertTrue($gate->allows('edit'));
        $this->assertFalse($gate->denies('edit'));
    }

    /**
     * A policy can answer with a reason, not just no.
     *
     * Every result was coerced to a boolean, so a message a policy went
     * to the trouble of writing never reached the user.
     */
    public function test_a_denial_can_carry_its_reason(): void
    {
        $gate = $this->gate(new ManagerUser(1));
        $gate->define('publish', static fn (): Response => Response::deny('Your plan does not include publishing.'));

        $decision = $gate->inspect('publish');

        $this->assertTrue($decision->denied());
        $this->assertSame('Your plan does not include publishing.', $decision->message());
    }

    public function test_the_reason_reaches_the_exception(): void
    {
        $gate = $this->gate(new ManagerUser(1));
        $gate->define('publish', static fn (): Response => Response::deny('Your plan does not include publishing.'));

        try {
            $gate->authorize('publish');
            $this->fail('the denial must throw');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Your plan does not include publishing.', $exception->getMessage());
            $this->assertTrue($exception->hasMessage());
        }
    }

    /** A denial can ask to be a 404, hiding that the record exists. */
    public function test_a_denial_can_choose_its_status(): void
    {
        $gate = $this->gate(new ManagerUser(1));
        $gate->define('view', static fn (): Response => Response::denyAsNotFound('No such thing.'));

        try {
            $gate->authorize('view');
            $this->fail('the denial must throw');
        } catch (AuthorizationException $exception) {
            $this->assertSame(404, $exception->status());
        }
    }

    /** An undefined ability names itself when it is refused. */
    public function test_an_undefined_ability_is_denied_by_name(): void
    {
        $decision = $this->gate()->inspect('nothing-defined');

        $this->assertTrue($decision->denied());
        $this->assertStringContainsString('nothing-defined', (string) $decision->message());
    }

    public function test_raw_returns_what_the_ability_returned(): void
    {
        $gate = $this->gate();
        $gate->define('counted', static fn (): int => 42);

        $this->assertSame(42, $gate->raw('counted'));
    }

    // ── Policies ──────────────────────────────────────────────────────

    public function test_a_policy_answers_for_its_class(): void
    {
        $gate = $this->gate(new ManagerUser(1));
        $gate->policy(Document::class, DocumentPolicy::class);

        $this->assertTrue($gate->allows('view', new Document(1)));
        $this->assertFalse($gate->allows('view', new Document(2)));
    }

    /** A policy's before() grants everything without each method checking. */
    public function test_a_policy_before_can_answer_for_every_ability(): void
    {
        $gate = $this->gate(new ManagerUser(99));
        $gate->policy(Document::class, DocumentPolicy::class);

        $this->assertTrue($gate->allows('view', new Document(2)), 'the administrator sees everything');
    }

    public function test_a_policy_message_survives(): void
    {
        $gate = $this->gate(new ManagerUser(1));
        $gate->policy(Document::class, DocumentPolicy::class);

        $this->assertSame('Not yours.', $gate->inspect('delete', new Document(2))->message());
    }

    /** A guesser finds a policy nothing registered. */
    public function test_a_policy_can_be_guessed(): void
    {
        $gate = $this->gate(new ManagerUser(1));
        $gate->guessPolicyNamesUsing(static fn (string $class): string => $class . 'Policy');

        $this->assertTrue($gate->allows('view', new Document(1)));
    }

    // ── Conditions ────────────────────────────────────────────────────

    public function test_allow_if_throws_when_the_condition_fails(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Not allowed.');

        $this->gate()->allowIf(false, 'Not allowed.');
    }

    public function test_allow_if_passes_when_it_holds(): void
    {
        $this->assertTrue($this->gate()->allowIf(true)->allowed());
    }

    public function test_deny_if_throws_when_the_condition_holds(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->gate()->denyIf(true, 'Refused.');
    }

    // ── Several abilities ─────────────────────────────────────────────

    public function test_check_requires_every_ability(): void
    {
        $gate = $this->gate();
        $gate->define('one', static fn (): bool => true);
        $gate->define('two', static fn (): bool => false);

        $this->assertFalse($gate->check(['one', 'two']));
        $this->assertTrue($gate->any(['one', 'two']));
        $this->assertFalse($gate->none(['one', 'two']));
    }

    /** A resource defines the usual five abilities at once. */
    public function test_a_resource_defines_its_abilities(): void
    {
        $gate = $this->gate();
        $gate->resource('documents', DocumentPolicy::class);

        foreach (['viewAny', 'view', 'create', 'update', 'delete'] as $ability) {
            $this->assertTrue($gate->has('documents.' . $ability));
        }
    }

    public function test_a_gate_can_answer_for_another_user(): void
    {
        $gate = $this->gate(new ManagerUser(1));
        $gate->define('own', static fn (mixed $user): bool => $user?->getAuthIdentifier() === 2);

        $this->assertFalse($gate->allows('own'));
        $this->assertTrue($gate->forUser(new ManagerUser(2))->allows('own'));
    }
}

// ── Doubles ───────────────────────────────────────────────────────────

class ManagerUser implements Authenticatable
{
    use RemembersUser;

    public function __construct(public int $id, public ?string $api_token = null) {}

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
        return password_hash('secret', PASSWORD_DEFAULT);
    }
}

class ManagerProvider implements UserProvider
{
    /** @var array<int|string, ManagerUser> */
    public array $users = [];

    public function add(ManagerUser $user): ManagerUser
    {
        return $this->users[$user->getAuthIdentifier()] = $user;
    }

    public function retrieveById(mixed $identifier): ?Authenticatable
    {
        return $this->users[$identifier] ?? null;
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        foreach ($this->users as $user) {
            if (isset($credentials['api_token']) && $user->api_token === $credentials['api_token']) {
                return $user;
            }
        }

        return null;
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

class Document
{
    public function __construct(public int $ownerId) {}
}

class DocumentPolicy
{
    use HandlesAuthorization;

    /** An administrator is allowed everything without each method saying so. */
    public function before(mixed $user, string $ability): ?bool
    {
        return $user?->getAuthIdentifier() === 99 ? true : null;
    }

    public function view(mixed $user, Document $document): bool
    {
        return $user?->getAuthIdentifier() === $document->ownerId;
    }

    public function delete(mixed $user, Document $document): Response
    {
        return $user?->getAuthIdentifier() === $document->ownerId
            ? $this->allow()
            : $this->deny('Not yours.');
    }

    public function viewAny(mixed $user): bool
    {
        return true;
    }

    public function create(mixed $user): bool
    {
        return true;
    }

    public function update(mixed $user): bool
    {
        return true;
    }
}

/** Just enough request to carry a bearer token. */
class TokenRequest
{
    public function __construct(private ?string $token) {}

    public function query(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function bearerToken(): ?string
    {
        return $this->token;
    }
}

