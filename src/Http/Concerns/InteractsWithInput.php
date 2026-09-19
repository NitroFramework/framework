<?php

namespace Nitro\Http\Concerns;

use BackedEnum;
use Closure;
use Nitro\Support\Carbon;
use Nitro\Support\Collection;
use Nitro\Validation\ValidationException;
use Nitro\Validation\Validator;

/**
 * Asking the request what it was actually given.
 *
 * {@see \Nitro\Http\Request::has()} answers whether a key arrived, which is not
 * the question a controller usually has: `?name=` arrives, and `has('name')` is
 * true for it. The difference between present and usable is what most of this
 * concern is about, and writing it out by hand at every call site
 * (`$request->input('x') !== null && $request->input('x') !== ''`) is how it
 * ends up written differently in different places.
 *
 * The casts are the same idea one level down. A checkbox arrives as '1', 'on',
 * 'true' or nothing at all depending on the browser and the markup, and a
 * controller that compares it to true is wrong about at least one of those.
 */
trait InteractsWithInput
{
    /**
     * Whether every given key is present AND not empty.
     *
     * '0' is filled — it is a value someone typed. '' and [] are not.
     */
    public function filled(string ...$keys): bool
    {
        foreach ($keys as $key) {
            if ($this->isEmptyString($key)) {
                return false;
            }
        }

        return true;
    }

    /** Whether every given key is absent or empty. */
    public function isNotFilled(string ...$keys): bool
    {
        foreach ($keys as $key) {
            if (! $this->isEmptyString($key)) {
                return false;
            }
        }

        return true;
    }

    /** Whether any one of the given keys is present and not empty. */
    public function anyFilled(string ...$keys): bool
    {
        foreach ($keys as $key) {
            if ($this->filled($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run $callback with the value when the key is filled.
     *
     * Returns the request so it chains, or $default's result when it is not —
     * which is what makes `->whenFilled('a', ...)->whenFilled('b', ...)` read
     * as a list of optional steps rather than a stack of ifs.
     */
    public function whenFilled(string $key, Closure $callback, ?Closure $default = null): static
    {
        if ($this->filled($key)) {
            $callback($this->input($key));

            return $this;
        }

        if ($default !== null) {
            $default();
        }

        return $this;
    }

    /** Run $callback with the value when the key is present, empty or not. */
    public function whenHas(string $key, Closure $callback, ?Closure $default = null): static
    {
        if ($this->has($key)) {
            $callback($this->input($key));

            return $this;
        }

        if ($default !== null) {
            $default();
        }

        return $this;
    }

    /** Whether every given key is absent from the input. */
    public function missing(string ...$keys): bool
    {
        return ! $this->has(...$keys);
    }

    /** Run $callback when the key is absent. */
    public function whenMissing(string $key, Closure $callback, ?Closure $default = null): static
    {
        if ($this->missing($key)) {
            $callback();

            return $this;
        }

        if ($default !== null) {
            $default();
        }

        return $this;
    }

    /** Whether any one of the given keys is present, empty or not. */
    public function hasAny(string ...$keys): bool
    {
        foreach ($keys as $key) {
            if ($this->has($key)) {
                return true;
            }
        }

        return false;
    }

    /** Alias of {@see \Nitro\Http\Request::has()}, for callers that read better this way. */
    public function exists(string ...$keys): bool
    {
        return $this->has(...$keys);
    }

    /**
     * A checkbox or flag as a real bool.
     *
     * '1', 'true', 'on' and 'yes' are true; everything else, including a missing
     * key, is false. An unchecked checkbox sends nothing at all, so the default
     * has to be false rather than null for the common case to be right.
     */
    public function boolean(?string $key = null, bool $default = false): bool
    {
        $value = $key === null ? null : $this->input($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /** An input as an int, or $default when it is absent or not numeric. */
    public function integer(string $key, int $default = 0): int
    {
        $value = $this->input($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /** An input as a float, or $default when it is absent or not numeric. */
    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->input($key);

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * An input as a string, or $default when it is absent.
     *
     * Not a cast of arrays or objects: a field that arrived as `a[]=1&a[]=2` is
     * not a string, and quietly turning it into "Array" is worse than saying so.
     */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key);

        if ($value === null || is_array($value) || is_object($value)) {
            return $default;
        }

        return (string) $value;
    }

    /**
     * An input parsed as a date, or null when it is absent or unparseable.
     *
     * Returns the framework's own Carbon so a controller gets the same type
     * date() and now() hand back, and null rather than throwing, because a bad
     * date in user input is a validation concern and this is not the validator.
     */
    public function date(string $key, ?string $format = null, ?string $timezone = null): ?Carbon
    {
        $value = $this->input($key);

        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        try {
            if ($format === null) {
                return Carbon::parse((string) $value, $timezone);
            }

            $zone = $timezone === null ? null : new \DateTimeZone($timezone);
            $parsed = Carbon::createFromFormat($format, (string) $value, $zone);
        } catch (\Throwable) {
            return null;
        }

        return $parsed instanceof Carbon ? $parsed : null;
    }

    /**
     * An input as a case of a backed enum, or null when it does not match one.
     *
     * The point is that a route or form carrying a status string never reaches
     * the domain as a string — an unknown value is null, not an invalid enum.
     *
     * @template T of BackedEnum
     * @param class-string<T> $enum
     * @return T|null
     */
    public function enum(string $key, string $enum): ?BackedEnum
    {
        $value = $this->input($key);

        if ($value === null || ! enum_exists($enum) || ! method_exists($enum, 'tryFrom')) {
            return null;
        }

        return $enum::tryFrom($value);
    }

    /**
     * The input, or part of it, as a Collection.
     *
     * @param string|array<int, string>|null $key
     */
    public function collect(string|array|null $key = null): Collection
    {
        if ($key === null) {
            return new Collection($this->all());
        }

        return new Collection($this->only(...(array) $key));
    }

    /**
     * Validate the request and return the validated input.
     *
     * Throws on failure rather than returning errors, so a controller reads as
     * the happy path: the ExceptionHandler turns a ValidationException into a
     * redirect back with the errors flashed, or a 422 for a client that asked
     * for JSON. That conversion is registered in ExceptionServiceProvider.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, string> $messages
     * @return array<string, mixed>  Only the keys that were validated.
     *
     * @throws ValidationException
     */
    public function validate(array $rules, array $messages = []): array
    {
        $data = $this->all();
        $validator = new Validator($data, $rules, $messages);

        if (! $validator->validate()) {
            throw new ValidationException($validator->errors());
        }

        return array_intersect_key($data, $rules);
    }

    /** Whether a key is absent, or present but an empty string or empty array. */
    private function isEmptyString(string $key): bool
    {
        $value = $this->input($key);

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return $value === null;
    }
}
