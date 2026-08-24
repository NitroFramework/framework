<?php

namespace Nitro\Exceptions;

/**
 * How often an exception type is allowed to be reported.
 *
 * The problem this solves: one broken dependency can throw thousands of times a
 * minute, and every one of those writes a log line (or a paid API call to an
 * error tracker). The first few tell you everything; the rest fill the disk.
 *
 * Returned from an ExceptionHandler::throttle() callback:
 *
 *   $handler->throttle(fn ($e) => $e instanceof PaymentGatewayException
 *       ? ReportRate::limit(10, 60)      // at most 10 a minute
 *       : ReportRate::sample(0.1));      // otherwise log one in ten
 */
final class ReportRate
{
    private function __construct(
        public readonly string $mode,
        public readonly int $maxAttempts = 0,
        public readonly int $decaySeconds = 60,
        public readonly ?string $key = null,
        public readonly float $chance = 1.0,
    ) {}

    /**
     * Allow at most $maxAttempts reports per $decaySeconds window.
     *
     * $key groups exceptions that should share a budget; it defaults to the
     * exception's class, so each type is limited independently.
     */
    public static function limit(int $maxAttempts, int $decaySeconds = 60, ?string $key = null): self
    {
        return new self('limit', $maxAttempts, $decaySeconds, $key);
    }

    /**
     * Report a random fraction of occurrences — 0.1 keeps one in ten. Useful
     * for a high-volume exception where you want a representative trickle
     * rather than a hard ceiling.
     */
    public static function sample(float $chance): self
    {
        return new self('sample', chance: max(0.0, min(1.0, $chance)));
    }

    /** No throttling — report every occurrence. */
    public static function unlimited(): self
    {
        return new self('unlimited');
    }

    /** Whether this rate imposes no restriction at all. */
    public function isUnlimited(): bool
    {
        return $this->mode === 'unlimited';
    }
}
