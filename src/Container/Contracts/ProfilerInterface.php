<?php

namespace Nitro\Container\Contracts;

/**
 * Records container activity for the dev-time profiler.
 *
 * The container depends only on this interface, held as a NULLABLE property: when
 * no profiler is attached (production) the calls are `$this->profiler?->...`, i.e.
 * a single null check and zero work. This keeps profiling/debugging out of the
 * container's core — the container does dependency injection, a profiler (which
 * lives with the dev tooling and merely implements this interface) does profiling.
 */
interface ProfilerInterface
{
    /** Record that a binding was registered. */
    public function recordRegistration(string $abstract, string $type = 'singleton'): void;

    /** Record that a pre-built instance was registered. */
    public function recordInstance(string $abstract): void;

    /** Mark the start of a resolution; returns an id to pass to endResolving(). */
    public function startResolving(string $abstract, string $method = 'get'): int;

    /** Mark the end of the resolution identified by $id. */
    public function endResolving(int $id, string $type = 'class', bool $cached = false): void;
}
