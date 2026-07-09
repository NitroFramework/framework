<?php

namespace Nitro\Coroutine\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Aggregates the failures from a Co::parallel() batch. Any tasks that DID succeed
 * are still available via results(); the per-key exceptions via throwables().
 */
class ParallelExecutionException extends RuntimeException
{
    /**
     * @param array<int|string, mixed>     $results    Successful results, keyed as input.
     * @param array<int|string, Throwable> $throwables Failures, keyed as input.
     */
    public function __construct(
        private readonly array $results,
        private readonly array $throwables,
    ) {
        $lines = '';
        foreach ($throwables as $key => $e) {
            $lines .= sprintf("  (%s) %s: %s%s", $key, $e::class, $e->getMessage(), PHP_EOL);
        }

        parent::__construct(
            sprintf('%d task(s) failed during Co::parallel():%s%s', count($throwables), PHP_EOL, $lines)
        );
    }

    /** @return array<int|string, mixed> */
    public function results(): array
    {
        return $this->results;
    }

    /** @return array<int|string, Throwable> */
    public function throwables(): array
    {
        return $this->throwables;
    }
}
