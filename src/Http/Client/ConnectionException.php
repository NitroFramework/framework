<?php

namespace Nitro\Http\Client;

use RuntimeException;

/**
 * Thrown when a request never reached the server — DNS failure, refused
 * connection, or a timeout.
 */
class ConnectionException extends RuntimeException
{
}
