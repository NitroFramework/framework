<?php

namespace Nitro\Auth;

use Closure;
use InvalidArgumentException;
use Nitro\Auth\Contracts\Guard;
use Nitro\Auth\Contracts\UserProvider;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Foundation\Contracts\ConfigRepository;

/**
 * Resolves guards by name from config/auth.php.
 *
 *     auth()->user()              // the default guard
 *     auth()->guard('api')->user()
 *
 * An application usually has one guard; it has more the moment part of
 * it is an API, where a session means nothing and a token is the whole
 * credential. Calls not named here go to the default guard, so the
 * common case reads as though there were only one.
 *
 * @mixin \Nitro\Auth\Contracts\StatefulGuard
 */
class AuthManager
{
    /** @var array<string, Guard> Guards already built, by name. */
    protected array $guards = [];

    /** @var array<string, Closure(array, string): Guard> */
    protected array $customGuards = [];

    /** @var array<string, Closure(array): UserProvider> */
    protected array $customProviders = [];

    /** Overrides the configured default, for the rest of this request. */
    protected ?string $defaultGuard = null;

    public function __construct(
        protected ConfigRepository $config,
        protected ClassResolver $resolver,
        protected Closure $sessionResolver,
        protected mixed $events = null,
    ) {}

    public function guard(?string $name = null): Guard
    {
        $name ??= $this->getDefaultDriver();

        return $this->guards[$name] ??= $this->resolve($name);
    }

    protected function resolve(string $name): Guard
    {
        $config = $this->getGuardConfig($name);

        if (isset($this->customGuards[$config['driver'] ?? ''])) {
            return ($this->customGuards[$config['driver']])($config, $name);
        }

        if (isset($this->customGuards[$name])) {
            return ($this->customGuards[$name])($config, $name);
        }

        return match ($config['driver'] ?? 'session') {
            'session' => $this->createSessionDriver($name, $config),
            'token' => $this->createTokenDriver($name, $config),
            default => throw new InvalidArgumentException(
                "Auth guard [{$name}] uses undefined driver [{$config['driver']}]."
            ),
        };
    }

    /** @param array<string, mixed> $config */
    public function createSessionDriver(string $name, array $config): SessionGuard
    {
        return new SessionGuard(
            $this->createUserProvider($config['provider'] ?? null),
            ($this->sessionResolver)(),
            $this->events,
            $name,
        );
    }

    /** @param array<string, mixed> $config */
    public function createTokenDriver(string $name, array $config): TokenGuard
    {
        return new TokenGuard(
            $this->createUserProvider($config['provider'] ?? null),
            null,
            (string) ($config['input_key'] ?? 'api_token'),
            (string) ($config['storage_key'] ?? 'api_token'),
            (bool) ($config['hash'] ?? false),
        );
    }

    /**
     * The provider a guard draws its users from.
     *
     * A guard naming no provider gets the default one, which is what an
     * application with a single users table wants and need not say.
     */
    public function createUserProvider(?string $name = null): UserProvider
    {
        $name ??= $this->config->get('auth.defaults.provider');

        if ($name === null) {
            return $this->resolver->resolve(UserProvider::class);
        }

        $config = (array) $this->config->get("auth.providers.{$name}", []);

        if (isset($this->customProviders[$config['driver'] ?? ''])) {
            return ($this->customProviders[$config['driver']])($config);
        }

        return match ($config['driver'] ?? 'eloquent') {
            'eloquent' => new EloquentUserProvider(
                (string) ($config['model'] ?? $this->config->get('auth.model', 'App\\Models\\User')),
            ),
            'database' => new DatabaseUserProvider((string) ($config['table'] ?? 'users')),
            default => throw new InvalidArgumentException(
                "Auth user provider [{$name}] uses undefined driver [{$config['driver']}]."
            ),
        };
    }

    /**
     * Add a guard of your own.
     *
     * @param Closure(array<string, mixed>, string): Guard $callback
     */
    public function extend(string $driver, Closure $callback): static
    {
        $this->customGuards[$driver] = $callback;

        return $this;
    }

    /**
     * Add a user provider of your own.
     *
     * @param Closure(array<string, mixed>): UserProvider $callback
     */
    public function provider(string $driver, Closure $callback): static
    {
        $this->customProviders[$driver] = $callback;

        return $this;
    }

    /**
     * Authenticate by a callback rather than a configured driver.
     *
     * @param Closure(mixed, ?UserProvider): mixed $callback
     */
    public function viaRequest(string $name, Closure $callback): static
    {
        return $this->extend($name, static fn (): RequestGuard => new RequestGuard($callback));
    }

    /** Make a named guard the default for the rest of this request. */
    public function shouldUse(?string $name): void
    {
        $this->defaultGuard = $name ?? $this->getConfiguredDefault();
    }

    public function setDefaultDriver(string $name): void
    {
        $this->defaultGuard = $name;
    }

    public function getDefaultDriver(): string
    {
        return $this->defaultGuard ?? $this->getConfiguredDefault();
    }

    protected function getConfiguredDefault(): string
    {
        return (string) ($this->config->get('auth.defaults.guard') ?? 'web');
    }

    /**
     * The config for a named guard.
     *
     * An application with no auth.guards block gets a session guard, so
     * the framework works before anything has been configured.
     *
     * @return array<string, mixed>
     */
    protected function getGuardConfig(string $name): array
    {
        $config = $this->config->get("auth.guards.{$name}");

        if (is_array($config)) {
            return $config;
        }

        if ($name === $this->getConfiguredDefault()) {
            return ['driver' => 'session'];
        }

        throw new InvalidArgumentException("Auth guard [{$name}] is not defined.");
    }

    /** How anything asking for the current user should find them. */
    public function userResolver(): Closure
    {
        return fn (?string $guard = null) => $this->guard($guard)->user();
    }

    /** @return array<string, Guard> */
    public function hasResolvedGuards(): array
    {
        return $this->guards;
    }

    /** Drop the built guards, so the next call builds them again. */
    public function forgetGuards(): static
    {
        $this->guards = [];

        return $this;
    }

    /** Anything not named here is the default guard's business. */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->guard()->{$method}(...$parameters);
    }
}
