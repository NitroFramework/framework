<?php

namespace Nitro\Support;

use RuntimeException;

/**
 * More than one matched where exactly one was required.
 *
 * The count is on the exception, because the number is usually the first thing
 * worth knowing: two is a data problem, two thousand is a missing filter.
 */
class MultipleItemsFoundException extends RuntimeException
{
    public function __construct(
        public readonly int $count = 0,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : "Expected one matching item, found {$count}.",
            $code,
            $previous,
        );
    }
}
