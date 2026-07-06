<?php

namespace Nitro\Cache\Exceptions;

use RuntimeException;

/**
 * Thrown when a cache backend fails to read a payload it should have
 * been able to read — corrupt file contents, unreadable file, or an
 * unserialize() failure on a payload that wasn't the literal `false`.
 *
 * Distinct from a cache miss (the key didn't exist). Callers that rely
 * on cache should treat this as a real I/O failure rather than silently
 * treating it as "key wasn't there."
 */
class CacheReadException extends RuntimeException
{
}
