<?php

namespace Nitro\Exceptions\Events;

use Throwable;

/**
 * Payload for exception.occurred and exception.handled.
 *
 * exception.occurred fires for everything that reaches the handler, including
 * what the log deliberately ignores — a 404, a validation failure — so a
 * listener shipping to an error tracker should filter on the class rather than
 * assume the framework already did. exception.handled fires only for the ones
 * that get rendered into a page, and carries the status that page will have.
 */
class ExceptionEvent
{
    /**
     * @param Throwable $exception The exception, after the handler mapped it.
     * @param int|null  $status    HTTP status it will render as, on exception.handled only.
     */
    public function __construct(
        public readonly Throwable $exception,
        public readonly ?int $status = null,
    ) {}
}
