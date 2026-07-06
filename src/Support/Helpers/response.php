<?php

use Nitro\Http\RedirectResponse;
use Nitro\Http\Redirector;
use Nitro\Http\Response;

if (!function_exists('json')) {
    /**
     * Send a JSON response
     * 
     * @param array $data Data to encode as JSON
     * @param int $status HTTP status code
     * @param array $headers Additional headers
     * @return void
     */
    function json(array $data, int $status = 200, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json');

        foreach ($headers as $name => $value) {
            header("$name: $value");
        }

        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('text')) {
    /**
     * Send a plain text response
     * 
     * @param string $text Text to send
     * @param int $status HTTP status code
     * @return void
     */
    function text(string $text, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $text;
    }
}

if (!function_exists('html')) {
    /**
     * Send an HTML response
     * 
     * @param string $html HTML to send
     * @param int $status HTTP status code
     * @return void
     */
    function html(string $html, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }
}

if (!function_exists('response')) {
    /**
     * Create a response
     * 
     * @param string $content
     * @param int $status
     * @param array $headers
     * @return void
     */
    function response(string $content = '', int $status = 200, array $headers = []): void
    {
        http_response_code($status);

        foreach ($headers as $name => $value) {
            header("$name: $value");
        }

        echo $content;
    }
}

if (!function_exists('redirect')) {
    /**
     * Laravel-style redirect helper.
     *
     * - redirect('/path')  → a RedirectResponse (chain ->withInput()/->withErrors()/->with()).
     * - redirect()         → a Redirector for fluent ->route()/->intended()/->back()/->away().
     *
     * @param string|null $url URL to redirect to, or null for the fluent builder
     * @param int $status HTTP status code (301, 302, …)
     * @return RedirectResponse|Redirector
     */
    function redirect(?string $url = null, int $status = 302)
    {
        $redirector = new Redirector();
        return $url !== null ? $redirector->to($url, $status) : $redirector;
    }
}

if (!function_exists('back')) {
    /**
     * Build a redirect response to the previous page (Referer), or $fallback
     * when there's no referer. Chainable like redirect().
     *
     * @param string $fallback URL to use when no Referer header is present
     * @param int $status HTTP status code
     * @return RedirectResponse
     */
    function back(string $fallback = '/', int $status = 302): RedirectResponse
    {
        $referer = app('request')->header('referer') ?? $fallback;
        return Response::redirect($referer, $status);
    }
}

if (!function_exists('abort')) {
    /**
     * Abort the request with an HTTP status code by throwing an HttpException.
     *
     * Throwing (rather than the old echo + exit) routes the failure through the
     * Kernel's exception handler, so it gets proper status, HTMX/JSON content
     * negotiation and the response-ready hooks — instead of a raw <h1> that
     * bypassed the whole lifecycle. HttpException extends RuntimeException.
     *
     * @param int    $code    HTTP status code
     * @param string $message Error message (defaults to the status text)
     * @return never
     *
     * @throws \Nitro\Exceptions\HttpException
     */
    function abort(int $code, string $message = ''): never
    {
        if ($message === '') {
            $message = [
                400 => 'Bad Request',
                401 => 'Unauthorized',
                403 => 'Forbidden',
                404 => 'Not Found',
                405 => 'Method Not Allowed',
                419 => 'Page Expired',
                422 => 'Unprocessable Entity',
                429 => 'Too Many Requests',
                500 => 'Internal Server Error',
                502 => 'Bad Gateway',
                503 => 'Service Unavailable',
            ][$code] ?? 'Error';
        }

        throw new \Nitro\Exceptions\HttpException($code, $message);
    }
}