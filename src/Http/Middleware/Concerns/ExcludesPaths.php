<?php

namespace Nitro\Http\Middleware\Concerns;

use Nitro\Http\Request;

/**
 * The exemption list a middleware checks a request against.
 *
 * Shared, so two of them cannot disagree about whether a leading slash
 * matters.
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
             * exemption can name a query string when it has to.
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
