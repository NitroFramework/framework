<?php

namespace Nitro\Process;

use RuntimeException;

/**
 * Thrown by ProcessResult::throw() when a process exited non-zero.
 */
class ProcessFailedException extends RuntimeException
{
    public function __construct(
        public readonly ProcessResult $result,
    ) {
        parent::__construct(
            sprintf(
                'The command "%s" failed with exit code %d.%s',
                $result->command(),
                $result->exitCode(),
                $result->errorOutput() === '' ? '' : "\n" . trim($result->errorOutput())
            ),
            $result->exitCode()
        );
    }
}
