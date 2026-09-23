<?php

namespace Nitro\Auth\Access;

use Nitro\Auth\Exceptions\AuthorizationException;
use Nitro\Container\Contracts\ClassResolver;

/**
 * Decides whether a user may do a thing.
 *
 * An ability is either a closure registered with define(), or a method
 * on a policy registered for the class being acted on. Either may
 * answer with a bare true or false, or with a {@see Response} carrying
 * the reason — which is what a user sees when the answer is no.
 */
class Gate
{
    /** @var array<string, callable|string> */
    protected array $abilities = [];

    /** @var array<class-string, class-string> */
    protected array $policies = [];

    /** @var array<int, callable> Run before every check. */
    protected array $beforeCallbacks = [];

    /** @var array<int, callable> Run after every check. */
    protected array $afterCallbacks = [];

    protected mixed $userResolver = null;

    /** How a class name is turned into a policy name when none is registered. */
    protected mixed $policyGuesser = null;

    /** The abilities a resource policy covers, and their methods. */
    protected const RESOURCE_ABILITIES = [
        'viewAny' => 'viewAny',
        'view' => 'view',
        'create' => 'create',
        'update' => 'update',
        'delete' => 'delete',
    ];

    public function __construct(
        protected ClassResolver $resolver,
        ?callable $userResolver = null,
    ) {
        $this->userResolver = $userResolver;
    }

    public function resolveUsersUsing(callable $resolver): static
    {
        $this->userResolver = $resolver;

        return $this;
    }

    // ── Registering ───────────────────────────────────────────────────

    public function define(string $ability, callable|string $callback): static
    {
        $this->abilities[$ability] = $callback;

        return $this;
    }

    /** Name the policy that answers for a class. */
    public function policy(string $class, string $policy): static
    {
        $this->policies[$class] = $policy;

        return $this;
    }

    /**
     * Define the usual five abilities for a resource at once.
     *
     * @param array<string, string> $abilities Override the default set.
     */
    public function resource(string $name, string $class, ?array $abilities = null): static
    {
        foreach ($abilities ?? static::RESOURCE_ABILITIES as $ability => $method) {
            $this->define($name . '.' . $ability, $class . '@' . $method);
        }

        return $this;
    }

    public function before(callable $callback): static
    {
        $this->beforeCallbacks[] = $callback;

        return $this;
    }

    public function after(callable $callback): static
    {
        $this->afterCallbacks[] = $callback;

        return $this;
    }

    /** Name how an unregistered class is matched to a policy. */
    public function guessPolicyNamesUsing(callable $callback): static
    {
        $this->policyGuesser = $callback;

        return $this;
    }

    // ── Reading the map ───────────────────────────────────────────────

    public function has(string|array $ability): bool
    {
        foreach ((array) $ability as $name) {
            if (! isset($this->abilities[$name])) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, callable|string> */
    public function abilities(): array
    {
        return $this->abilities;
    }

    /** @return array<class-string, class-string> */
    public function policies(): array
    {
        return $this->policies;
    }

    /**
     * The policy answering for a class, resolved from the container.
     *
     * A registered policy wins; failing that the guesser is asked, so
     * an application following a naming convention need register none.
     */
    public function getPolicyFor(object|string $class): ?object
    {
        $class = is_object($class) ? $class::class : $class;

        foreach ($this->policies as $covered => $policy) {
            if ($class === $covered || is_subclass_of($class, $covered)) {
                return $this->resolvePolicy($policy);
            }
        }

        if ($this->policyGuesser !== null) {
            $guessed = ($this->policyGuesser)($class);

            foreach ((array) $guessed as $policy) {
                if (is_string($policy) && class_exists($policy)) {
                    return $this->resolvePolicy($policy);
                }
            }
        }

        return null;
    }

    public function resolvePolicy(string $policy): object
    {
        return $this->resolver->resolve($policy);
    }

    // ── Asking ────────────────────────────────────────────────────────

    public function allows(string $ability, mixed $arguments = []): bool
    {
        return $this->check($ability, $arguments);
    }

    public function denies(string $ability, mixed $arguments = []): bool
    {
        return ! $this->check($ability, $arguments);
    }

    /**
     * Whether every named ability is allowed.
     *
     * @param array<int, string>|string $abilities
     */
    public function check(array|string $abilities, mixed $arguments = []): bool
    {
        foreach ((array) $abilities as $ability) {
            if (! $this->inspect($ability, $arguments)->allowed()) {
                return false;
            }
        }

        return true;
    }

    /** Whether any one of them is. */
    public function any(array|string $abilities, mixed $arguments = []): bool
    {
        foreach ((array) $abilities as $ability) {
            if ($this->inspect($ability, $arguments)->allowed()) {
                return true;
            }
        }

        return false;
    }

    public function none(array|string $abilities, mixed $arguments = []): bool
    {
        return ! $this->any($abilities, $arguments);
    }

    /**
     * The decision, with its reason.
     *
     * check() answers yes or no; this is what to call when the reason
     * matters — to show it to the user, or to act on its status.
     */
    public function inspect(string $ability, mixed $arguments = []): Response
    {
        $result = $this->raw($ability, $arguments);

        if ($result instanceof Response) {
            return $result;
        }

        return $result ? Response::allow() : $this->defaultDenialResponse($ability);
    }

    /**
     * Whatever the ability actually returned, uncoerced.
     */
    public function raw(string $ability, mixed $arguments = []): mixed
    {
        $arguments = is_array($arguments) ? array_values($arguments) : [$arguments];
        $user = $this->user();

        $result = $this->callBeforeCallbacks($user, $ability, $arguments);

        if ($result === null) {
            $result = $this->callAuthorizer($user, $ability, $arguments);
        }

        return $this->callAfterCallbacks($user, $ability, $arguments, $result);
    }

    /**
     * Throw unless the ability is allowed.
     *
     * @throws AuthorizationException
     */
    public function authorize(string $ability, mixed $arguments = []): Response
    {
        return $this->inspect($ability, $arguments)->authorize();
    }

    /**
     * Throw unless the condition holds.
     *
     * For a check that is not worth a named ability — a flag on a
     * record, a step already completed.
     *
     * @throws AuthorizationException
     */
    public function allowIf(mixed $condition, ?string $message = null, mixed $code = null): Response
    {
        return $this->authorizeOnCondition($condition, $message, $code, true);
    }

    /**
     * Throw when the condition holds.
     *
     * @throws AuthorizationException
     */
    public function denyIf(mixed $condition, ?string $message = null, mixed $code = null): Response
    {
        return $this->authorizeOnCondition($condition, $message, $code, false);
    }

    private function authorizeOnCondition(mixed $condition, ?string $message, mixed $code, bool $allowWhen): Response
    {
        if ($condition instanceof Response) {
            return $condition->authorize();
        }

        if (is_callable($condition)) {
            $condition = $condition();
        }

        $allowed = $allowWhen ? (bool) $condition : ! $condition;

        return ($allowed ? Response::allow($message, $code) : Response::deny($message, $code))->authorize();
    }

    /**
     * What a denial means when the ability itself said nothing.
     *
     * Named, because "this action is unauthorized" on its own leaves
     * whoever is reading the log to work out which action.
     */
    public function defaultDenialResponse(?string $ability = null): Response
    {
        return Response::deny(
            $ability === null
                ? 'This action is unauthorized.'
                : "This action is unauthorized: {$ability}.",
        );
    }

    /** A gate answering for somebody other than the current user. */
    public function forUser(mixed $user): static
    {
        $clone = clone $this;
        $clone->userResolver = static fn (): mixed => $user;

        return $clone;
    }

    // ── Running an ability ────────────────────────────────────────────

    /**
     * Give the before callbacks, and the policy's own, a chance to answer.
     *
     * A policy's before() is what grants an administrator everything
     * without every method repeating the check.
     */
    protected function callBeforeCallbacks(mixed $user, string $ability, array $arguments): mixed
    {
        foreach ($this->beforeCallbacks as $callback) {
            $result = $callback($user, $ability, $arguments);

            if ($result !== null) {
                return $result;
            }
        }

        $policy = $this->policyForArguments($arguments);

        if ($policy !== null && method_exists($policy, 'before')) {
            $result = $policy->before($user, $ability, ...$arguments);

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /** Let the after callbacks replace the answer. */
    protected function callAfterCallbacks(mixed $user, string $ability, array $arguments, mixed $result): mixed
    {
        foreach ($this->afterCallbacks as $callback) {
            $replacement = $callback($user, $ability, $result, $arguments);

            if ($replacement !== null) {
                $result = $replacement;
            }
        }

        return $result;
    }

    /**
     * Run the policy method or the defined callback.
     *
     * The result is returned as it came back, so a Response keeps its
     * message all the way to the caller.
     */
    protected function callAuthorizer(mixed $user, string $ability, array $arguments): mixed
    {
        $policy = $this->policyForArguments($arguments);

        if ($policy !== null && method_exists($policy, $ability)) {
            return $policy->{$ability}($user, ...$arguments);
        }

        if (! isset($this->abilities[$ability])) {
            return false;
        }

        $callback = $this->abilities[$ability];

        if (is_string($callback)) {
            [$class, $method] = str_contains($callback, '@')
                ? explode('@', $callback, 2)
                : [$callback, $ability];

            return $this->resolver->resolve($class)->{$method}($user, ...$arguments);
        }

        return $callback($user, ...$arguments);
    }

    /** The policy for the first argument, when it is an object or a class name. */
    protected function policyForArguments(array $arguments): ?object
    {
        if ($arguments === []) {
            return null;
        }

        $subject = $arguments[0];

        if (is_object($subject)) {
            return $this->getPolicyFor($subject);
        }

        return is_string($subject) && class_exists($subject) ? $this->getPolicyFor($subject) : null;
    }

    protected function user(): mixed
    {
        return $this->userResolver === null ? null : ($this->userResolver)();
    }
}
