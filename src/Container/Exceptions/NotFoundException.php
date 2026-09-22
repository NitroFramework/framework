<?php

namespace Nitro\Container\Exceptions;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Thrown when a requested service is not registered and cannot be resolved.
 *
 * A RuntimeException so that a caller guarding a lookup with `catch
 * (RuntimeException)` still catches it, and a PSR-11 NotFoundExceptionInterface
 * so that one written against the standard does too.
 */
class NotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
}
