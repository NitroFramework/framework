<?php

namespace Nitro\Actions;

use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Exceptions\HttpException;
use Nitro\Http\Request;
use Nitro\Validation\ValidationException;
use Nitro\Validation\Validator;

/**
 * Base class for a single-action class — one class that does one job in handle().
 *
 * The same class can be used three ways, with no magic (no backtrace, no global
 * resolution hook):
 *
 *   1. As an object    — CreateUser::run($name, $email)   → handle($name, $email)
 *   2. Resolved         — CreateUser::make()               → container instance
 *   3. As a route       — Route::post('/users', CreateUser::class)
 *
 * As a route target, the router runs the controller pipeline below: authorize()
 * (403 on false) → rules() validation (throws on failure) → the action body
 * (asController() if defined, else handle()) with route params + DI autowired →
 * optional jsonResponse()/htmlResponse() negotiation. Define only the hooks you
 * need — they all no-op by default.
 *
 * To also run on the queue, compose Nitro\Queue\Dispatchable; to react to events,
 * register handle() as a listener. Those stay composition rather than being baked
 * in here, so the Action base has exactly one concern: the HTTP + object shapes.
 */
abstract class Action
{
    /** Set for the duration of the controller pipeline so hooks can read the request. */
    protected ?Request $request = null;

    /** The validated subset of the input, after rules() runs. @var array<string, mixed> */
    protected array $validated = [];

    // ─── Object mode ──────────────────────────────────────────────────────────

    /** Resolve the action from the container with its constructor deps autowired. */
    public static function make(): static
    {
        return app(static::class);
    }

    /** Resolve the action and run handle() with the given arguments. */
    public static function run(mixed ...$arguments): mixed
    {
        return static::make()->handle(...$arguments);
    }

    /** Run only when the condition holds; otherwise return null. */
    public static function runIf(bool $condition, mixed ...$arguments): mixed
    {
        return $condition ? static::run(...$arguments) : null;
    }

    // ─── Controller-mode hooks (override as needed) ─────────────────────────────

    /** Whether the current request is allowed to run this action. */
    public function authorize(): bool
    {
        return true;
    }

    /** Validation rules for the request, keyed by field. Empty = no validation. */
    public function rules(): array
    {
        return [];
    }

    /** Custom validation messages (optional). */
    public function messages(): array
    {
        return [];
    }

    // ─── The controller pipeline (invoked by the router; not called directly) ───

    /**
     * Run this action as a route target: authorize, validate, then invoke the
     * action body with the route parameters and any autowired dependencies.
     *
     * The container is passed in (the dispatcher already holds it) rather than
     * fetched globally — this is framework-internal, so the dependency is explicit
     * here without leaking into the user-facing hooks.
     *
     * @param array<string, mixed> $parameters Route parameters, by name.
     */
    public function runAsController(Request $request, array $parameters, ContainerInterface $container): mixed
    {
        $this->request = $request;

        if (! $this->authorize()) {
            throw new HttpException(403, 'This action is unauthorized.');
        }

        $rules = $this->rules();
        if ($rules !== []) {
            $data      = $request->all();
            $validator = new Validator($data, $rules, $this->messages());

            if (! $validator->validate()) {
                throw new ValidationException($validator->errors());
            }

            $this->validated = array_intersect_key($data, $rules);
        }

        $method   = method_exists($this, 'asController') ? 'asController' : 'handle';
        $response = $container->call([$this, $method], $parameters);

        return $this->negotiate($response, $request);
    }

    /** The validated input (only the fields named in rules()). @return array<string, mixed> */
    public function validated(): array
    {
        return $this->validated;
    }

    /** The request bound during the controller pipeline. */
    protected function request(): ?Request
    {
        return $this->request;
    }

    /**
     * Optional response shaping: if the action defines jsonResponse()/htmlResponse(),
     * pick one based on whether the client wants JSON. Otherwise pass the value
     * through untouched (the Kernel already turns arrays/views into responses).
     */
    protected function negotiate(mixed $response, Request $request): mixed
    {
        $wantsJson = $request->expectsJson();

        if ($wantsJson && method_exists($this, 'jsonResponse')) {
            return $this->jsonResponse($response, $request);
        }

        if (! $wantsJson && method_exists($this, 'htmlResponse')) {
            return $this->htmlResponse($response, $request);
        }

        return $response;
    }
}
