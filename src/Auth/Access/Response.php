<?php

namespace Nitro\Auth\Access;

use Nitro\Auth\Exceptions\AuthorizationException;
use Stringable;

/**
 * An authorization decision, with a reason.
 *
 * A policy returning false says only that the answer was no; returning
 * one of these says why, which is the difference between "Forbidden"
 * and telling the user their subscription lapsed.
 */
class Response implements Stringable
{
    /** The HTTP status a denial should become, or null for the default. */
    protected ?int $status = null;

    public function __construct(
        protected bool $allowed,
        public ?string $message = null,
        public mixed $code = null,
    ) {}

    public static function allow(?string $message = null, mixed $code = null): static
    {
        return new static(true, $message, $code);
    }

    public static function deny(?string $message = null, mixed $code = null): static
    {
        return new static(false, $message, $code);
    }

    /** Deny with a particular HTTP status rather than 403. */
    public static function denyWithStatus(int $status, ?string $message = null, mixed $code = null): static
    {
        return static::deny($message, $code)->withStatus($status);
    }

    /**
     * Deny as though the thing did not exist.
     *
     * For a record whose existence is itself private: a 403 confirms it
     * is there, which a 404 does not.
     */
    public static function denyAsNotFound(?string $message = null, mixed $code = null): static
    {
        return static::denyWithStatus(404, $message, $code);
    }

    public function allowed(): bool
    {
        return $this->allowed;
    }

    public function denied(): bool
    {
        return ! $this->allowed;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    public function code(): mixed
    {
        return $this->code;
    }

    /**
     * Throw when this response is a denial.
     *
     * @throws AuthorizationException
     */
    public function authorize(): static
    {
        if ($this->denied()) {
            throw (new AuthorizationException($this->message ?? 'This action is unauthorized.'))
                ->setResponse($this);
        }

        return $this;
    }

    public function withStatus(?int $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function asNotFound(): static
    {
        return $this->withStatus(404);
    }

    public function status(): ?int
    {
        return $this->status;
    }

    /** @return array{allowed: bool, message: ?string, code: mixed} */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed(),
            'message' => $this->message(),
            'code' => $this->code(),
        ];
    }

    public function __toString(): string
    {
        return (string) $this->message();
    }
}
