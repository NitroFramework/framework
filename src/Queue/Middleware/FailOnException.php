<?php

namespace Nitro\Queue\Middleware;

use Throwable;

/**
 * Fails a job immediately for exceptions that retrying cannot fix.
 *
 *     return [new FailOnException([ValidationException::class])];
 *
 * Anything not listed is rethrown so the usual retry budget applies.
 */
class FailOnException
{
    /**
     * @param array<int, class-string<Throwable>> $exceptions Types to give up on.
     */
    public function __construct(
        protected array $exceptions = [],
    ) {}

    public function handle(mixed $job, callable $next): mixed
    {
        try {
            return $next($job);
        } catch (Throwable $exception) {
            if ($this->shouldFail($exception) && method_exists($job, 'fail')) {
                $job->fail($exception);

                return null;
            }

            throw $exception;
        }
    }

    private function shouldFail(Throwable $exception): bool
    {
        foreach ($this->exceptions as $type) {
            if ($exception instanceof $type) {
                return true;
            }
        }

        return false;
    }
}