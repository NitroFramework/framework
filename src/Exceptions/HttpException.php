<?php

namespace Nitro\Exceptions;

use RuntimeException;
use Throwable;

/**
 * An HTTP exception carrying a status code, rendered as the matching response.
 *
 * Headers as well as a status, because several statuses are not honestly
 * expressible without them. A 429 without Retry-After tells a client to back off
 * for an unknown length of time, so it retries immediately; a 401 without
 * WWW-Authenticate does not say how to authenticate; a 503 without Retry-After
 * tells a crawler nothing about when to come back. Each of those is a protocol
 * violation clients act on, not a nicety.
 */
class HttpException extends RuntimeException
{
    /**
     * @param  array<string, string>  $headers  sent with the response
     */
    public function __construct(
        private int $statusCode,
        string $message = '',
        ?Throwable $previous = null,
        private array $headers = [],
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): static
    {
        $this->headers = array_merge($this->headers, $headers);

        return $this;
    }

    /**
     * "Come back in this many seconds."
     *
     * The one header worth a named constructor, because it is the one most
     * often left off: a rate limit or a maintenance window that does not say
     * when to retry is a client hammering the door.
     */
    public function retryAfter(int $seconds): static
    {
        return $this->withHeaders(['Retry-After' => (string) $seconds]);
    }
}
