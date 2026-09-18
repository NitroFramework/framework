<?php

namespace Nitro\Http;

use Nitro\View\Factory;

/**
 * Builds responses, for code that would rather ask an object than a class.
 *
 *     $factory->json(['ok' => true]);
 *     $factory->view('orders.show', ['order' => $order]);
 *
 * The view factory and redirector are injected rather than reached for through
 * helpers, so this class can be constructed and tested without an application
 * booted around it.
 */
class ResponseFactory
{
    public function __construct(
        private Factory $view,
        private Redirector $redirector,
    ) {}

    /** @param array<string, string> $headers */
    public function make(string $content = '', int $status = 200, array $headers = []): Response
    {
        return new Response($content, $status, $headers);
    }

    /** @param array<string, string> $headers */
    public function noContent(int $status = 204, array $headers = []): Response
    {
        return $this->make('', $status, $headers);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    public function view(string $view, array $data = [], int $status = 200, array $headers = []): Response
    {
        return $this->make($this->view->make($view, $data)->render(), $status, $headers)
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * @param array<string, string> $headers
     * @param int $options Flags for json_encode, on top of the defaults.
     */
    public function json(mixed $data = [], int $status = 200, array $headers = [], int $options = 0): Response
    {
        $response = Response::json($data, $status, $options);

        foreach ($headers as $name => $value) {
            $response->header($name, $value);
        }

        return $response;
    }

    /** @param array<string, string> $headers */
    public function redirectTo(string $url, int $status = 302): RedirectResponse
    {
        return $this->redirector->to($url, $status);
    }

    /** @param array<string, mixed> $parameters */
    public function redirectToRoute(string $name, array $parameters = [], int $status = 302): RedirectResponse
    {
        return $this->redirector->route($name, $parameters, $status);
    }
}
