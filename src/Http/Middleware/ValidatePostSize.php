<?php

namespace Nitro\Http\Middleware;

use Nitro\Http\Exceptions\PostTooLargeException;
use Nitro\Http\Request;
use Nitro\Http\Response;

/**
 * Rejects a request whose body PHP refused to read.
 *
 * PHP discards a body over post_max_size and carries on, so the
 * application sees an empty form with a Content-Length that says
 * otherwise, and reports required fields the user did fill in.
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
     * The ini value may carry a unit suffix; 0 means no limit.
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
