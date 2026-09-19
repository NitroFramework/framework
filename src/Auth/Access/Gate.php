<?php

namespace Nitro\Auth\Access;

use Nitro\Auth\Exceptions\AuthorizationException;
use Nitro\Container\Contracts\ContainerInterface as Container;
use Throwable;

/**
 * Decides whether the current user may perform an action.
 *
 *     Gate::define('update-post', fn ($user, $post) => $user->id === $post->user_id);
 *     Gate::policy(Post::class, PostPolicy::class);
 *
 *     Gate::allows('update-post', $post);
 *     Gate::authorize('update-post', $post);
 */
class Gate
{
    /** @var array<string, callable|string> Abilities, keyed by name. */
    protected array $abilities = [];

    /** @var array<class-string, class-string> Policies, keyed by the class they cover. */
    protected array $policies = [];

    /** @var array<int, callable> Run before every check; a non-null result decides it. */
    protected array $beforeCallbacks = [];

    /** @var array<int, callable> Run after every check; may replace the result. */
    protected array $afterCallbacks = [];

    /** Resolves the user a check is made against. */
    protected mixed $userResolver = null;

    public function __construct(
        protected Container $container,
        ?callable $userResolver = null,
    ) {
        $this->userResolver = $userResolver;
    }

    /** Set how the gate finds the current user. */
    public function resolveUsersUsing(callable $resolver): static
    {
        $this->userResolver = $resolver;

        return $this;
    }

    /** Define an ability by name. */
    public function define(string $ability, callable|string $callback): static
    {
        $this->abilities[$ability] = $callback;

        return $this;
    }

    /**
     * Register a policy class covering a model.
     *
     * @param class-string $class
     * @param class-string $policy
     */
    public function policy(string $class, string $policy): static
    {
        $this->policies[$class] = $policy;

        return $this;
    }

    /** The policy covering a class, or null. */
    public function getPolicyFor(object|string $class): ?object
    {
        $class = is_object($class) ? $class::class : $class;

        foreach ($this->policies as $covered => $policy) {
            if ($class === $covered || is_subclass_of($class, $covered)) {
                return $this->container->resolve($policy);
            }
        }

        return null;
    }

    /** Run before every check; returning non-null decides it outright. */
    public function before(callable $callback): static
    {
        $this->beforeCallbacks[] = $callback;

        return $this;
    }

    /** Run after every check; returning non-null replaces the result. */
    public function after(callable $callback): static
    {
        $this->afterCallbacks[] = $callback;

        return $this;
    }

    public function has(string $ability): bool
    {
        return isset($this->abilities[$ability]);
    }

    /** @param mixed|array<int, mixed> $arguments */
    public function allows(string $ability, mixed $arguments = []): bool
    {
        return $this->check($ability, $arguments);
    }

    /** @param mixed|array<int, mixed> $arguments */
    public function denies(string $ability, mixed $arguments = []): bool
    {
        return ! $this->check($ability, $arguments);
    }

    /**
     * Whether the current user may do this.
     *
     * @param mixed|array<int, mixed> $arguments
     */
    public function check(string $ability, mixed $arguments = []): bool
    {
        $arguments = is_array($arguments) ? array_values($arguments) : [$arguments];
        $user = $this->user();

        foreach ($this->beforeCallbacks as $callback) {
            $result = $callback($user, $ability, $arguments);

            if ($result !== null) {
                return (bool) $result;
            }
        }

        $result = $this->callAuthorizer($user, $ability, $arguments);

        foreach ($this->afterCallbacks as $callback) {
            $replacement = $callback($user, $ability, $result, $arguments);

            if ($replacement !== null) {
                $result = (bool) $replacement;
            }
        }

        return (bool) $result;
    }

    /**
     * Check every ability given, requiring all of them.
     *
     * @param array<int, string>|string $abilities
     * @param mixed|array<int, mixed>   $arguments
     */
    public function any(array|string $abilities, mixed $arguments = []): bool
    {
        foreach ((array) $abilities as $ability) {
            if ($this->check($ability, $arguments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string>|string $abilities
     * @param mixed|array<int, mixed>   $arguments
     */
    public function none(array|string $abilities, mixed $arguments = []): bool
    {
        return ! $this->any($abilities, $arguments);
    }

    /**
     * Raise unless the current user may do this.
     *
     * @param  mixed|array<int, mixed> $arguments
     * @throws AuthorizationException
     */
    public function authorize(string $ability, mixed $arguments = []): void
    {
        if (! $this->check($ability, $arguments)) {
            throw new AuthorizationException("This action is unauthorized: {$ability}.");
        }
    }

    /** A gate that answers for the given user instead of the current one. */
    public function forUser(mixed $user): static
    {
        $clone = clone $this;
        $clone->userResolver = static fn (): mixed => $user;

        return $clone;
    }

    /**
     * Find who answers the ability and call it.
     *
     * @param array<int, mixed> $arguments
     */
    protected function callAuthorizer(mixed $user, string $ability, array $arguments): bool
    {
        $policy = $arguments !== [] && is_object($arguments[0])
            ? $this->getPolicyFor($arguments[0])
            : null;

        if ($policy !== null && method_exists($policy, $ability)) {
            return (bool) $policy->{$ability}($user, ...$arguments);
        }

        if (! isset($this->abilities[$ability])) {
            return false;
        }

        $callback = $this->abilities[$ability];

        if (is_string($callback)) {
            [$class, $method] = str_contains($callback, '@')
                ? explode('@', $callback, 2)
                : [$callback, $ability];

            return (bool) $this->container->resolve($class)->{$method}($user, ...$arguments);
        }

        return (bool) $callback($user, ...$arguments);
    }

    /** The user a check is made against, or null when nobody is signed in. */
    protected function user(): mixed
    {
        if ($this->userResolver !== null) {
            return ($this->userResolver)();
        }

        try {
            return $this->container->resolve('auth')->user();
        } catch (Throwable) {
            return null;
        }
    }
}
