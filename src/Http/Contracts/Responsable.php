<?php

namespace Nitro\Http\Contracts;

use Nitro\Http\Request;
use Nitro\Http\Response;

/**
 * An object that knows how to turn itself into a Response.
 *
 * Return one from a controller or Action and the Kernel calls toResponse() to
 * render it — the extension point for API resources, self-serializing DTOs, or
 * any type that wants control over its own HTTP representation, without the
 * Kernel having to know about it.
 *
 *   class UserResource implements Responsable
 *   {
 *       public function __construct(private User $user) {}
 *       public function toResponse(Request $request): Response
 *       {
 *           return Response::json(['id' => $this->user->id, 'name' => $this->user->name]);
 *       }
 *   }
 */
interface Responsable
{
    public function toResponse(Request $request): Response;
}
