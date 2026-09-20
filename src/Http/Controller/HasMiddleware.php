<?php

namespace Nitro\Http\Controller;

/**
 * A controller that declares its own middleware.
 *
 * Static, so the list is readable without constructing the controller — which
 * is what lets the kernel assemble the stack before anything is resolved.
 *
 *     public static function middleware(): array
 *     {
 *         return [
 *             'auth',
 *             new Middleware('verified', except: ['show']),
 *         ];
 *     }
 */
interface HasMiddleware
{
    /**
     * The middleware to run for this controller's actions.
     *
     * @return array<int, string|Middleware>
     */
    public static function middleware(): array;
}
