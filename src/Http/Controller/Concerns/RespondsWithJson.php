<?php

namespace Nitro\Http\Controller\Concerns;

use Nitro\Http\Response;

/**
 * One envelope for every JSON response a controller sends.
 *
 *     {"success": true,  "message": "Success", "data": {…}}
 *     {"success": false, "message": "Validation failed", "errors": {…}}
 *
 * The shape matters more than any one response: a client parses it once. Opt
 * in per controller, or in a base class of your own when every endpoint of an
 * application answers the same way.
 */
trait RespondsWithJson
{
    /**
     * A JSON response with a status and optional headers.
     *
     * @param array<string, string> $headers
     */
    protected function json(mixed $data, int $status = 200, array $headers = []): Response
    {
        return Response::json((array) $data, $status)->withHeaders($headers);
    }

    /**
     * It worked, with whatever came back.
     */
    protected function success(mixed $data = null, string $message = 'Success'): Response
    {
        $response = ['success' => true, 'message' => $message];

        if ($data !== null) {
            $response['data'] = $data;
        }

        return $this->json($response);
    }

    /**
     * It did not, with the reason and anything explaining it.
     */
    protected function error(string $message, int $status = 400, mixed $errors = null): Response
    {
        $response = ['success' => false, 'message' => $message];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return $this->json($response, $status);
    }

    /**
     * Validate the request, answering with the envelope when it fails.
     *
     * Lives here rather than beside {@see PerformsValidation::validate()}
     * because the failure it returns is this envelope, not validation.
     *
     * @param  array<string, string|array<int, string>> $rules
     * @param  array<string, string>                    $messages
     * @return Response|null Null when the input is valid.
     */
    protected function validateRequest(array $rules, array $messages = []): ?Response
    {
        $errors = $this->validate($rules, null, $messages);

        return $errors === [] ? null : $this->error('Validation failed', 422, $errors);
    }
}
