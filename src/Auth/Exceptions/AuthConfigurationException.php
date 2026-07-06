<?php

namespace Nitro\Auth\Exceptions;

use RuntimeException;

/**
 * Thrown when the auth layer is misconfigured — most commonly when the
 * configured user model class does not exist. Distinct from "no user is
 * logged in" (which is signalled by user() returning null), so callers
 * never mistake a missing config for a guest visitor.
 */
class AuthConfigurationException extends RuntimeException
{
}
