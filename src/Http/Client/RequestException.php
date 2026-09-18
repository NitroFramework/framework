<?php

namespace Nitro\Http\Client;

use RuntimeException;

/**
 * Thrown by Response::throw() when a request came back 4xx or 5xx.
 */
class RequestException extends RuntimeException
{
    public function __construct(
        public readonly Response $response,
    ) {
        parent::__construct(
            'HTTP request returned status ' . $response->status() . ': ' . $this->summarise($response),
            $response->status()
        );
    }

    /** A short, single-line excerpt of the body for the message. */
    private function summarise(Response $response): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $response->body()) ?? '');

        return strlen($body) > 200 ? substr($body, 0, 200) . '…' : $body;
    }
}
