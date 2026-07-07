<?php

namespace Nitro\Http\Controller\Concerns;

use Nitro\Http\RedirectResponse;
use Nitro\Http\Response;

/**
 * Trait BuildsResponses
 * 
 * Provides helper methods for building HTTP responses from controllers.
 * 
 * Responsibilities:
 * - Rendering HTML views
 * - Returning JSON responses
 * - Returning success/error payloads
 * - Redirecting to URLs, back, or named routes
 * - Handling abort responses
 * 
 * Rules:
 * - Only responsible for generating HTTP responses.
 * - Does not handle request reading or validation.
 * - Relies on $this->container being available in the consuming controller.
 */
trait BuildsResponses
{
    /**
     * Render a view template with data and return an HTML response.
     */
    // protected function view(string $view, array $data = []): Response
    // {
    //     $viewRenderer = app('view');
    //     $content = $viewRenderer->render($view, $data);

    //     return Response::html($content)
    //         ->withViewContext($view, $data, $viewRenderer);
    // }

    // protected function view(string $view, array $data = [], string $layout = '', string $section = 'content'): Response
    // {
    //     $renderer = app('view');

    //     if ($layout) {
    //         $content = $renderer->render($view, $data);
    //         $renderer->getSectionManager()->forceSection($section, $content);
    //         $html = $renderer->renderPartial($layout, $data);
    //         return Response::html($html);
    //     }

    //     return Response::html($renderer->render($view, $data));
    // }

    /**
     * Return JSON response with given data and status code.
     */
    protected function json($data, int $status = 200, array $headers = []): Response
    {
        return Response::json((array) $data, $status)->withHeaders($headers);
    }

    /**
     * Return successful JSON response with optional data and message.
     */
    protected function success($data = null, string $message = 'Success'): Response
    {
        $response = ['success' => true, 'message' => $message];

        if ($data !== null) {
            $response['data'] = $data;
        }

        return $this->json($response);
    }

    /**
     * Return error JSON response with message and optional error details.
     */
    protected function error(string $message, int $status = 400, $errors = null): Response
    {
        $response = [
            'success' => false,
            'message' => $message
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return $this->json($response, $status);
    }

    /**
     * Redirect to a given URL. Chain ->withInput()/->withErrors()/->with().
     */
    protected function redirect(string $url, int $status = 302): RedirectResponse
    {
        return Response::redirect($url, $status);
    }

    /**
     * Redirect back to the previous page, or the fallback URL if no referer.
     */
    protected function back(string $fallback = '/'): RedirectResponse
    {
        $referer = app('request')->header('referer') ?? $fallback;
        return $this->redirect($referer);
    }

    /**
     * Redirect to a named route using the router service.
     */
    protected function redirectToRoute(string $name, array $parameters = []): RedirectResponse
    {
        $router = $this->container->get('router');
        $url = $router->route($name, $parameters);
        return $this->redirect($url);
    }

    /**
     * Abort the request with an HTTP error.
     *
     * Throws an HttpException so the failure routes through the Kernel's
     * exception handler — proper status, HTMX/JSON content negotiation and the
     * response-ready hooks. It never sends output or calls exit() directly: an
     * exit() here would kill a FrankenPHP worker and bypass the lifecycle.
     *
     * @throws \Nitro\Exceptions\HttpException
     */
    protected function abort(int $code, string $message = ''): never
    {
        \abort($code, $message);
    }

    /**
     * Get a config value by dot-notation key.
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        return $this->container->get('config')->get($key, $default);
    }
}
