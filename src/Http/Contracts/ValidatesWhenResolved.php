<?php

namespace Nitro\Http\Contracts;

/**
 * Marks a request object that validates itself as soon as it's resolved out of
 * the container (implemented by {@see \Nitro\Http\FormRequest}). Mirrors
 * Laravel's contract of the same name.
 */
interface ValidatesWhenResolved
{
    /** Authorize, then validate — throwing on failure. */
    public function validateResolved(): void;
}
