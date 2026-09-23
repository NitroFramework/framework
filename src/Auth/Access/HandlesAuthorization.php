<?php

namespace Nitro\Auth\Access;

/**
 * Lets a policy answer with a reason rather than a bare false.
 */
trait HandlesAuthorization
{
    protected function allow(?string $message = null, mixed $code = null): Response
    {
        return Response::allow($message, $code);
    }

    protected function deny(?string $message = null, mixed $code = null): Response
    {
        return Response::deny($message, $code);
    }

    /** Deny with a particular HTTP status rather than 403. */
    protected function denyWithStatus(int $status, ?string $message = null, mixed $code = null): Response
    {
        return Response::denyWithStatus($status, $message, $code);
    }

    /** Deny as though the thing did not exist, hiding that it does. */
    protected function denyAsNotFound(?string $message = null, mixed $code = null): Response
    {
        return Response::denyWithStatus(404, $message, $code);
    }
}
