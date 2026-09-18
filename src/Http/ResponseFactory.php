<?php

namespace Nitro\Http;

/**
 * Builds responses, for code that would rather ask an object than a class.
 *
 *     $factory->json(['ok' => true]);
 *     $factory->view('orders.show', ['order' => $order]);
 */
class ResponseFactory
{
    /** @param array<string, string> $headers */
    public function make(string $content = '', int $status = 200, array $headers = []): Response
    {
        $response = new Response($content, $status);

        foreach ($headers as $name => $value) {
            $response->header($name, $value);
        }

        return $response;
    }

    /** @param array<string, mixed> $data */
    public function json(mixed $data = [], int $status = 200, array $headers = []): Response
    {
        $response = Response::json(is_array($data) ? $data : [$data], $status);

        foreach ($headers as $name => $value) {
            $response->header($name, $value);
        }

        return $response;
    }

    /** @param array<string, mixed> $data */
    public function view(string $view, array $data = [], int $status = 200): Response
    {
        return Response::html(\view($view, $data), $status);
    }

    public function noContent(int $status = 204): Response
    {
        return new Response('', $status);
    }

    public function redirectTo(string $url, int $status = 302): RedirectResponse
    {
        return new RedirectResponse($url, $status);
    }
}
