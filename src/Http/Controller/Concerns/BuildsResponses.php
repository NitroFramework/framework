<?php

namespace Nitro\Http\Controller\Concerns;

use Nitro\Http\RedirectResponse;
use Nitro\Http\Response;

/**
 * Redirects and aborts, as methods on the controller.
 *
 * Every one of these is also a global helper, and the helper is the shorter
 * way to write it. The trait is for a controller that reads better saying
 * `$this->` — opt in where that is true and ignore it where it is not.
 *
 * Standalone: it reaches services through helpers, so it carries no
 * requirement on a base class or on another trait.
 */
trait BuildsResponses
{
    /**
     * Redirect to a URL. Chain `->withInput()`, `->withErrors()`, `->with()`.
     */
    protected function redirect(string $url, int $status = 302): RedirectResponse
    {
        return Response::redirect($url, $status);
    }

    /**
     * Redirect to wherever the request came from, or the fallback.
     */
    protected function back(string $fallback = '/'): RedirectResponse
    {
        return $this->redirect(request()->header('referer') ?? $fallback);
    }

    /**
     * Redirect to a named route.
     *
     * @param array<string, mixed> $parameters
     */
    protected function redirectToRoute(string $name, array $parameters = []): RedirectResponse
    {
        return $this->redirect(route($name, $parameters));
    }

    /**
     * Abort the request with an HTTP status.
     *
     * Throws rather than exiting, so the failure routes through the kernel's
     * exception handler — the right status, content negotiation and the
     * response-ready hooks. An exit() here would kill a worker.
     *
     * @throws \Nitro\Exceptions\HttpException
     */
    protected function abort(int $code, string $message = ''): never
    {
        \abort($code, $message);
    }

    /**
     * A config value by dot-notation key.
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        return \config($key, $default);
    }
}
