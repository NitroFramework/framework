<?php

namespace Nitro\Http\Controller\Concerns;

use Nitro\Http\Response;
use Nitro\Validation\Validator;

/**
 * Trait PerformsValidation
 * 
 * Provides validation capabilities to controllers
 * Uses the new Validator class internally
 * 
 * Supported rules:
 * - required: Field must not be empty
 * - nullable: Field can be empty (skips other validations if empty)
 * - string: Value must be a string
 * - numeric: Value must be numeric (int or numeric string)
 * - integer: Value must be an actual integer
 * - email: Value must be a valid email address
 * - date: Value must be a valid date (YYYY-MM-DD)
 * - max:N: For strings, max length; for numbers, max value
 * - min:N: For strings, min length; for numbers, min value
 * - in:val1,val2: Value must be one of the listed values
 * - regex:pattern: Value must match the regex pattern
 * - url: Value must be a valid URL
 * - confirmed: Value must match {field}_confirmation field
 * - unique:table,column: Value must be unique in database table
 */
trait PerformsValidation
{
    /**
     * Validate data against rules and return errors array
     * 
     * @param array $rules Field => rule string (rules separated by |)
     * @param array|null $data Data to validate (defaults to request input)
     * @param array $messages Custom error messages
     * @return array Field => error messages (empty array if no errors)
     */
    protected function validate(
        array $rules,
        ?array $data = null,
        array $messages = []
    ): array {
        $data = $data ?? $this->input();

        $validator = $this->makeValidator($data, $rules, $messages);
        $validator->validate();

        $errors = [];
        foreach ($validator->errors()->all() as $field => $msgs) {
            $errors[$field] = $msgs[0] ?? '';
        }

        return $errors;
    }

    /**
     * Validate request input and return Response if validation fails
     * 
     * @param array $rules Field => rule string
     * @param array $messages Custom error messages
     * @return Response|null Returns error response or null if validation passes
     */
    protected function validateRequest(
        array $rules,
        array $messages = []
    ): ?Response {
        $errors = $this->validate($rules, null, $messages);

        if (!empty($errors)) {
            return $this->error('Validation failed', 422, $errors);
        }

        return null;
    }

    /**
     * Create a new Validator instance (for advanced usage)
     * 
     * @param array $data Data to validate
     * @param array $rules Validation rules
     * @param array $messages Custom error messages
     * @return Validator
     */
    protected function makeValidator(
        array $data,
        array $rules,
        array $messages = []
    ): Validator {
        return new Validator($data, $rules, $messages);
    }

    /**
     * Return an error response.
     *
     * Provided by the consuming controller (typically via BuildsResponses).
     */
    abstract protected function error(
        string $message,
        int $code,
        array $data = []
    ): Response;

    // input() is provided by HandlesRequests with signature
    //   input(?string $key = null, mixed $default = null): mixed
    // Calling it with no arguments still returns the full input array, so the
    // validate() helper below works unchanged. We don't redeclare an
    // `abstract input(): array` here because the two signatures would clash.
}
