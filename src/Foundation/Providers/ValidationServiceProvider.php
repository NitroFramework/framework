<?php

namespace Nitro\Foundation\Providers;

use Nitro\Http\Request;
use Nitro\Validation\ValidationException;
use Nitro\Validation\Validator;

/**
 * Wires the Validation layer into the HTTP request via a macro, so a developer
 * writes exactly what they'd write in Laravel:
 *
 *   $data = request()->validate([
 *       'email'    => 'required|email',
 *       'password' => 'required',
 *   ]);
 *
 * On success it returns the validated subset. On failure it throws a pure
 * {@see ValidationException} — the exception-handling layer converts that into a
 * redirect-back (web) or 422 JSON (AJAX) response, so neither the macro nor the
 * Validation layer touches Http responses.
 *
 * Registered as a macro (not a method on Request) to keep the Http core free of
 * any dependency on the Validation layer.
 */
class ValidationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Request::macro('validate', function (array $rules, array $messages = []) {
            /** @var Request $this */
            $data = $this->all();

            $validator = new Validator($data, $rules, $messages);

            // validate() runs the rules and returns false on failure (fails()
            // alone only inspects the not-yet-populated error bag).
            if (!$validator->validate()) {
                throw new ValidationException($validator->errors());
            }

            // Return only the fields that were actually validated (Laravel's
            // $request->validate() contract).
            return array_intersect_key($data, $rules);
        });
    }
}
