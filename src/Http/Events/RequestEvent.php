<?php

namespace Nitro\Http\Events;

/**
 * Payload for request.received, request.handled, response.sending and
 * response.sent.
 *
 * The four describe one request at four moments, so they share a shape. The
 * status is null on request.received because nothing has decided it yet, and
 * set on the other three.
 */
class RequestEvent
{
    /**
     * @param string   $method HTTP verb, uppercase.
     * @param string   $path   Request path, without query string.
     * @param int|null $status Response status once there is one; null on request.received.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly ?int $status = null,
    ) {}
}
