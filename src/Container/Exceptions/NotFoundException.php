<?php

namespace Nitro\Container\Exceptions;

use RuntimeException;

/**
 * Thrown when a requested service is not registered and cannot be resolved.
 *
 * Extends RuntimeException so existing `catch (RuntimeException)` blocks keep
 * working, while giving callers a dedicated type to catch "service not found"
 * specifically. Mirrors PSR-11's NotFoundExceptionInterface semantics without
 * taking on the psr/container dependency.
 */
class NotFoundException extends RuntimeException
{
}
