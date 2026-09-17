<?php

namespace Nitro\Container;

/**
 * How long a resolved service lives.
 *
 * The ordering is the point: a service may depend on anything that lives at
 * least as long as it does, and never on anything shorter. A process-lived
 * service that takes a request-lived one keeps the first request's copy for
 * the life of the worker and hands it to every request after — the defect is
 * invisible under FPM, where the process ends with the request, and silent
 * under Thrust, where it simply serves the wrong data.
 */
enum Lifetime: int
{
    /** Rebuilt on every resolution; takes the lifetime of whatever consumes it. */
    case Transient = 0;

    /** One per request, dropped when the worker resets between them. */
    case Request = 1;

    /** One per process, surviving every reset until the worker recycles. */
    case Process = 2;

    public function outlives(self $other): bool
    {
        return $this->value > $other->value;
    }

    /** The binding call that declares this lifetime, for use in error messages. */
    public function declaredBy(): string
    {
        return match ($this) {
            self::Transient => 'bind()',
            self::Request => 'scoped()',
            self::Process => 'singleton() or instance()',
        };
    }
}
