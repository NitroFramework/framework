<?php

namespace Nitro\Database\Model;

use RuntimeException;

/**
 * Thrown by findOrFail()/firstOrFail() when the query matched nothing.
 *
 * It exists so the exception layer can tell "this record does not exist" apart
 * from "something broke". The former is a 404 — the ordinary case of a user
 * following a stale link — and {@see \Nitro\Exceptions\ExceptionHandler::prepareException()}
 * maps it to one. Before this type existed both threw a bare RuntimeException
 * and every missing record rendered as a 500.
 *
 * Extends RuntimeException, so code already catching that still catches this.
 */
class ModelNotFoundException extends RuntimeException
{
    /** @var class-string|null The model class that was queried. */
    protected ?string $model = null;

    /** @var array<int, mixed> The id(s) that were not found. */
    protected array $ids = [];

    /** Build the exception for a model class and the id(s) that missed. */
    public static function forModel(string $model, mixed $id = null): self
    {
        $ids = $id === null ? [] : (array) $id;

        $message = $ids === []
            ? "No query results for model [{$model}]."
            : "No query results for model [{$model}] " . implode(', ', array_map('strval', $ids)) . '.';

        $exception = new self($message);
        $exception->model = $model;
        $exception->ids = $ids;

        return $exception;
    }

    /** The model class that was queried. */
    public function getModel(): ?string
    {
        return $this->model;
    }

    /** The id(s) that were not found. */
    public function getIds(): array
    {
        return $this->ids;
    }

    /**
     * Context recorded with this exception when it IS logged (an app that calls
     * stopIgnoring() on 404s, say) — the handler picks this up automatically.
     */
    public function context(): array
    {
        return array_filter(['model' => $this->model, 'ids' => $this->ids]);
    }
}
