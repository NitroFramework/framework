<?php

namespace Nitro\Http\Middleware\Concerns;

use Nitro\Http\Request;

/**
 * The exemption list a middleware checks a request against.
 *
 * Shared because several of them need the same thing — a webhook endpoint
 * exempt from CSRF, a status page exempt from maintenance mode — and each
 * spelling the match out again is how two of them end up disagreeing about
 * whether a leading slash matters.
 */
trait ExcludesPaths
{
    protected function inExceptArray(Request $request): bool
    {
        foreach ($this->getExcludedPaths() as $except) {
            if ($except !== '/') {
                $except = trim($except, '/');
            }

            /*
             * Matched against the full URL as well as the path, so an
             * exemption can name a query string when it has to — a callback
             * URL that is only exempt for one action.
             */
            if ($request->fullUrlIs($except) || $request->is($except)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    public function getExcludedPaths(): array
    {
        return $this->except ?? [];
    }
}
