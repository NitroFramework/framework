<?php

namespace Nitro\Http;

/**
 * Fluent redirect builder — what the no-argument redirect() helper returns, so
 * controllers read exactly like Laravel:
 *
 *   return redirect()->route('dashboard');
 *   return redirect()->intended('/dashboard');
 *   return redirect()->back()->withInput()->withErrors($errors);
 *
 * Every method returns a {@see RedirectResponse}, so the flash API
 * (withInput/withErrors/with) chains straight off any of them.
 */
class Redirector
{
    /** Redirect to a raw URL. */
    public function to(string $url, int $status = 302): RedirectResponse
    {
        return new RedirectResponse($url, $status);
    }

    /** Redirect to a named route. */
    public function route(string $name, array $parameters = [], int $status = 302): RedirectResponse
    {
        return $this->to(app('router')->route($name, $parameters), $status);
    }

    /** Redirect to the previous page (Referer), or $fallback when absent. */
    public function back(string $fallback = '/', int $status = 302): RedirectResponse
    {
        return $this->to(app('request')->header('referer') ?? $fallback, $status);
    }

    /**
     * Redirect to the URL the user was headed to before authentication
     * intercepted them, falling back to $default. Consumes the stored intended
     * URL (the guest/auth middleware sets it).
     */
    public function intended(string $default = '/', int $status = 302): RedirectResponse
    {
        return $this->to(app('auth')->getIntendedUrl($default) ?? $default, $status);
    }

    /** Redirect to an external URL (semantic alias of to()). */
    public function away(string $url, int $status = 302): RedirectResponse
    {
        return $this->to($url, $status);
    }
}
