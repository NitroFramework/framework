<?php

namespace Nitro\Inertia\Middleware;

use Nitro\Inertia\EncryptHistoryMiddleware;

/**
 * The middleware to name in a route.
 *
 * Empty on purpose: the behaviour lives in the base class, so an application
 * that needs a condition extends that instead of replacing this.
 */
class EncryptHistory extends EncryptHistoryMiddleware
{
}
