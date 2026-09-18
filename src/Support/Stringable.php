<?php

namespace Nitro\Support;

use ArrayAccess;
use JsonSerializable;

/**
 * A fluent wrapper around a string.
 *
 * Every method on {@see Str} is reachable here without being redeclared: the
 * call is forwarded with the wrapped value as the first argument, and a string
 * result is rewrapped so the chain continues. Anything else — a bool from
 * contains(), an int from length() — is returned as-is and ends the chain.
 */
class Stringable implements ArrayAccess, JsonSerializable, \Stringable
{
    use Conditionable;

    public function __construct(protected string $value = '') {}

    /**
     * Forward to the matching Str method, rewrapping string results.
     *
     * Only methods that take the subject first can be forwarded this way. Any
     * whose arguments are ordered differently are declared explicitly below;
     * one that is neither raises rather than quietly passing the subject as
     * somebody else's argument.
     */
    public function __call(string $method, array $arguments): mixed
    {
        if (! method_exists(Str::class, $method)) {
            throw new \BadMethodCallException(
                sprintf('Method %s::%s() does not exist.', static::class, $method)
            );
        }

        if (! self::takesSubjectFirst($method)) {
            throw new \BadMethodCallException(sprintf(
                'Str::%s() does not take the subject as its first argument and has no '
                    . 'fluent equivalent on %s.',
                $method,
                static::class
            ));
        }

        $result = Str::{$method}($this->value, ...$arguments);

        return is_string($result) ? new static($result) : $result;
    }

    /**
     * Whether a Str method's first parameter is the string being operated on.
     *
     * Decided by parameter name and memoized, so the reflection is paid once
     * per method for the life of the process rather than on every call.
     */
    private static function takesSubjectFirst(string $method): bool
    {
        static $memo = [];

        if (isset($memo[$method])) {
            return $memo[$method];
        }

        $parameters = (new \ReflectionMethod(Str::class, $method))->getParameters();

        if ($parameters === []) {
            return $memo[$method] = false;
        }

        return $memo[$method] = in_array(
            $parameters[0]->getName(),
            ['subject', 'haystack', 'value', 'string'],
            true
        );
    }

    // ─── Methods whose static form takes the subject last ─────────────────

    public function slug(string $separator = '-'): static
    {
        return new static(Str::slug($this->value, $separator));
    }

    public function replace(string|array $search, string|array $replace): static
    {
        return new static(Str::replace($search, $replace, $this->value));
    }

    public function replaceStart(string $search, string $replace): static
    {
        return new static(Str::replaceStart($search, $replace, $this->value));
    }

    public function replaceEnd(string $search, string $replace): static
    {
        return new static(Str::replaceEnd($search, $replace, $this->value));
    }

    /** @param array<int, string> $replace */
    public function replaceArray(string $search, array $replace): static
    {
        return new static(Str::replaceArray($search, $replace, $this->value));
    }

    public function replaceMatches(string $pattern, string|callable $replace, int $limit = -1): static
    {
        return new static(Str::replaceMatches($pattern, $replace, $this->value, $limit));
    }

    public function remove(string|array $search, bool $caseSensitive = true): static
    {
        return new static(Str::remove($search, $this->value, $caseSensitive));
    }

    /** @param array<string, string> $map */
    public function swap(array $map): static
    {
        return new static(Str::swap($map, $this->value));
    }

    public function is(string|array $pattern): bool
    {
        return Str::is($pattern, $this->value);
    }

    public function isMatch(string|array $pattern): bool
    {
        return Str::isMatch($pattern, $this->value);
    }

    public function match(string $pattern): static
    {
        return new static(Str::match($pattern, $this->value));
    }

    /** @return array<int, string> */
    public function matchAll(string $pattern): array
    {
        return Str::matchAll($pattern, $this->value);
    }

    /** @param array{radius?: int, omission?: string} $options */
    public function excerpt(string $phrase = '', array $options = []): ?static
    {
        $excerpt = Str::excerpt($this->value, $phrase, $options);

        return $excerpt === null ? null : new static($excerpt);
    }

    // ─── Terminators ──────────────────────────────────────

    public function __toString(): string
    {
        return $this->value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isEmpty(): bool
    {
        return $this->value === '';
    }

    public function isNotEmpty(): bool
    {
        return $this->value !== '';
    }

    // ─── Operations with no Str equivalent ────────────────

    public function append(string ...$values): static
    {
        return new static($this->value . implode('', $values));
    }

    public function prepend(string ...$values): static
    {
        return new static(implode('', $values) . $this->value);
    }

    public function trim(?string $characters = null): static
    {
        return new static($characters === null ? trim($this->value) : trim($this->value, $characters));
    }

    public function ltrim(?string $characters = null): static
    {
        return new static($characters === null ? ltrim($this->value) : ltrim($this->value, $characters));
    }

    public function rtrim(?string $characters = null): static
    {
        return new static($characters === null ? rtrim($this->value) : rtrim($this->value, $characters));
    }

    public function explode(string $delimiter, int $limit = PHP_INT_MAX): Collection
    {
        return new Collection(explode($delimiter, $this->value, $limit));
    }

    public function split(int $length): Collection
    {
        return new Collection(str_split($this->value, max(1, $length)));
    }

    public function substr(int $start, ?int $length = null): static
    {
        return new static(mb_substr($this->value, $start, $length));
    }

    public function replaceFirst(string $search, string $replace): static
    {
        $position = strpos($this->value, $search);

        if ($position === false || $search === '') {
            return new static($this->value);
        }

        return new static(substr_replace($this->value, $replace, $position, strlen($search)));
    }

    public function replaceLast(string $search, string $replace): static
    {
        $position = strrpos($this->value, $search);

        if ($position === false || $search === '') {
            return new static($this->value);
        }

        return new static(substr_replace($this->value, $replace, $position, strlen($search)));
    }


    public function whenEmpty(callable $callback): static
    {
        return $this->when($this->isEmpty(), $callback);
    }

    public function pipe(callable $callback): static
    {
        $result = $callback($this);

        return $result instanceof static ? $result : new static((string) $result);
    }

    public function tap(callable $callback): static
    {
        $callback($this);

        return $this;
    }

    public function dd(): never
    {
        var_dump($this->value);
        exit(1);
    }

    // ─── Interfaces ───────────────────────────────────────

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->value[$offset]);
    }

    public function offsetGet(mixed $offset): string
    {
        return $this->value[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->value[$offset] = (string) $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \BadMethodCallException('Cannot unset an offset on a string.');
    }
}
