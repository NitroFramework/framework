<?php

namespace Nitro\Http;

use Nitro\Validation\ErrorBag;

/**
 * A redirect Response with a Laravel-style fluent flash API.
 *
 * Returned by Response::redirect(), the redirect()/back() helpers, and the
 * controller BuildsResponses trait, so controllers can express the common
 * "redirect back with the old input and validation errors" without hand-rolling
 * session keys:
 *
 *   return back()->withInput()->withErrors($validator->errors());
 *
 * Everything it flashes lives for exactly one further request (the redirect
 * target), then ages out — so the form that reads old()/errors() shows them
 * once and they vanish, no manual cleanup.
 */
class RedirectResponse extends Response
{
    /** Password-like fields never flashed back into old input. */
    protected const NEVER_FLASH = ['password', 'password_confirmation', 'current_password'];

    public function __construct(string $url, int $status = self::HTTP_REDIRECT, array $headers = [])
    {
        parent::__construct('', $status, array_merge(['Location' => $url], $headers));
    }

    /**
     * Flash one key/value (or an array of pairs) to the session for the next
     * request — e.g. ->with('status', 'Profile updated').
     */
    public function with(string|array $key, mixed $value = null): self
    {
        $pairs = is_array($key) ? $key : [$key => $value];
        foreach ($pairs as $k => $v) {
            session()->flash($k, $v);
        }
        return $this;
    }

    /**
     * Flash request input back so the form can repopulate via old(). Defaults to
     * the current request's input (minus password fields); pass an explicit
     * array to flash exactly that.
     */
    public function withInput(?array $input = null): self
    {
        session()->flash('_old_input', $input ?? $this->defaultInput());
        return $this;
    }

    /**
     * Flash validation errors so the view can read them via errors(). Accepts a
     * flat [field => message] array, an [field => [messages]] array, an
     * {@see ErrorBag}, or any validator exposing errors().
     */
    public function withErrors(mixed $errors): self
    {
        session()->flash('errors', $this->normalizeErrors($errors));
        return $this;
    }

    /**
     * Current request input with password-like fields stripped out.
     */
    protected function defaultInput(): array
    {
        $request = app('request');
        $input = ($request instanceof Request) ? $request->all() : [];

        foreach (self::NEVER_FLASH as $field) {
            unset($input[$field]);
        }

        return $input;
    }

    /**
     * Reduce any supported error shape to the flat [field => message] form the
     * errors() helper and the auth views expect.
     */
    protected function normalizeErrors(mixed $errors): array
    {
        if ($errors instanceof ErrorBag) {
            $errors = $errors->all();
        } elseif (is_object($errors) && method_exists($errors, 'errors')) {
            $errors = $errors->errors();
            if ($errors instanceof ErrorBag) {
                $errors = $errors->all();
            }
        }

        $flat = [];
        foreach ((array) $errors as $field => $message) {
            $flat[$field] = is_array($message) ? ((string) ($message[0] ?? '')) : (string) $message;
        }

        return $flat;
    }
}
