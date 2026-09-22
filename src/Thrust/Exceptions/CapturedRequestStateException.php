<?php

namespace Nitro\Container\Exceptions;

use RuntimeException;

/**
 * Something that outlives a request was still holding one of its objects when
 * it ended.
 *
 * Raised by the worker reset while capture detection is on, which is a suite or
 * a developer's worker rather than production. It names the property path
 * rather than only the class, because the holder is usually not the class that
 * did the capturing — a singleton keeps a collaborator, and the collaborator
 * kept the user.
 */
class CapturedRequestStateException extends RuntimeException
{
}
