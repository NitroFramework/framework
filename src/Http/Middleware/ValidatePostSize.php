<?php

namespace Nitro\Http\Middleware;

use Nitro\Http\Exceptions\PostTooLargeException;
use Nitro\Http\Request;
use Nitro\Http\Response;

/**
 * Rejects a request whose body PHP refused to read.
 *
 * When a body exceeds post_max_size PHP discards it and carries on, so the
 * application sees an empty form with a Content-Length that says otherwise.
 * Left alone, that surfaces as "these fields are required" for fields the
 * user did fill in. Comparing the declared length against the limit turns it
 * into the failure it actually is.
 */
class ValidatePostSize
{
    /**
     * @throws PostTooLargeException When the body is larger than PHP accepts.
     */
    public function handle(Request $request, callable $next): Response
    {
        $max = $this->postMaxSize();

        if ($max > 0 && (int) $request->server('CONTENT_LENGTH') > $max) {
            throw new PostTooLargeException();
        }

        return $next($request);
    }

    /**
     * post_max_size in bytes.
     *
     * The ini value carries a unit suffix — 8M, 512K — unless it is already
     * a plain number, and 0 means no limit.
     */
    protected function postMaxSize(): int
    {
        $postMaxSize = ini_get('post_max_size');

        if ($postMaxSize === false || is_numeric($postMaxSize)) {
            return (int) $postMaxSize;
        }

        $metric = strtoupper(substr($postMaxSize, -1));
        $value = (int) $postMaxSize;

        return match ($metric) {
            'K'     => $value * 1024,
            'M'     => $value * 1048576,
            'G'     => $value * 1073741824,
            default => $value,
        };
    }
}
