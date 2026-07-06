<?php

namespace Nitro\Http;

use Nitro\Exceptions\HttpException;
use Nitro\Http\Contracts\ValidatesWhenResolved;
use Nitro\Validation\Validator;
use Nitro\Validation\ValidationException;

/**
 * Base class for self-validating form requests — Laravel's FormRequest.
 *
 *   class StoreUserRequest extends FormRequest
 *   {
 *       public function authorize(): bool { return true; }
 *       public function rules(): array {
 *           return ['email' => 'required|email|unique:users,email', 'password' => 'required|min:8'];
 *       }
 *   }
 *
 *   public function store(StoreUserRequest $request) {  // already validated here
 *       User::create($request->validated());
 *   }
 *
 * Validation runs the moment the container resolves the request (in the
 * constructor): authorize() first (403 on false), then rules() — on failure it
 * throws {@see ValidationException}, so the user is redirected back with errors
 * and old input before the controller method ever runs.
 */
abstract class FormRequest implements ValidatesWhenResolved
{
    /** @var array<string, mixed> The validated subset of the input. */
    protected array $validatedData = [];

    public function __construct()
    {
        $this->validateResolved();
    }

    /** Validation rules, keyed by field. */
    abstract public function rules(): array;

    /** Whether the current user may make this request. */
    public function authorize(): bool
    {
        return true;
    }

    /** Custom validation messages (optional). */
    public function messages(): array
    {
        return [];
    }

    public function validateResolved(): void
    {
        if (!$this->authorize()) {
            throw new HttpException(403, 'This action is unauthorized.');
        }

        $request = $this->request();
        $data    = $request->all();

        $validator = new Validator($data, $this->rules(), $this->messages());

        if (!$validator->validate()) {
            throw new ValidationException($validator->errors());
        }

        $this->validatedData = array_intersect_key($data, $this->rules());
    }

    /** The validated input (only the fields named in rules()). */
    public function validated(): array
    {
        return $this->validatedData;
    }

    // ─── Input proxies to the underlying request ──────────────────────────

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->request()->input($key, $default);
    }

    public function all(): array
    {
        return $this->request()->all();
    }

    public function only(string ...$keys): array
    {
        return $this->request()->only(...$keys);
    }

    public function except(string ...$keys): array
    {
        return $this->request()->except(...$keys);
    }

    /** The current HTTP request. */
    protected function request(): Request
    {
        return app('request');
    }
}
