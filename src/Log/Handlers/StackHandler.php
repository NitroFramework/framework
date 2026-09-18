<?php

namespace Nitro\Log\Handlers;

use Throwable;

/**
 * Writes the same line to several channels at once.
 *
 * What lets an application log to a file and to stderr from one call. With
 * `ignore_exceptions` on, one channel failing does not stop the others — a
 * full disk should not also cost you the copy going to the platform.
 */
class StackHandler implements Handler
{
    /**
     * @param array<int, Handler> $handlers
     */
    public function __construct(
        protected array $handlers,
        protected bool $ignoreExceptions = false,
    ) {}

    public function write(string $level, string $message, array $context = []): void
    {
        foreach ($this->handlers as $handler) {
            if (! $this->ignoreExceptions) {
                $handler->write($level, $message, $context);

                continue;
            }

            try {
                $handler->write($level, $message, $context);
            } catch (Throwable) {
                //
            }
        }
    }

    /**
     * Get the channels this stack writes to.
     *
     * @return array<int, Handler>
     */
    public function getHandlers(): array
    {
        return $this->handlers;
    }
}
