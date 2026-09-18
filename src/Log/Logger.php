<?php

namespace Nitro\Log;

use Nitro\Log\Handlers\Handler;

/**
 * One log channel: a handler plus the minimum level it records.
 *
 * Exposes the eight PSR-3 severities. Not a formal Psr\Log\LoggerInterface
 * implementation — there is no psr/log dependency — but the method names and
 * the (level, message, context) signature match it, so swapping in a PSR-3
 * logger later is mechanical.
 */
class Logger
{
    /**
     * Severities in ascending order, for comparing against the channel's floor.
     *
     * @var array<string, int>
     */
    protected const LEVELS = [
        'debug'     => 0,
        'info'      => 1,
        'notice'    => 2,
        'warning'   => 3,
        'error'     => 4,
        'critical'  => 5,
        'alert'     => 6,
        'emergency' => 7,
    ];

    /**
     * @param string $level The lowest severity this channel records.
     */
    public function __construct(
        protected Handler $handler,
        protected string $level = 'debug',
    ) {}

    /** System is unusable. */
    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    /** Action must be taken immediately. */
    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    /** Critical conditions. */
    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /** Runtime errors that do not require immediate action. */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** Exceptional occurrences that are not errors. */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** Normal but significant events. */
    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    /** Interesting events. */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** Detailed debug information. */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * Write a line at an arbitrary level, if the channel records that severity.
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if ($this->shouldRecord($level)) {
            $this->handler->write($level, $message, $context);
        }
    }

    /**
     * Determine whether a severity clears the channel's floor.
     *
     * An unrecognised level is recorded rather than dropped: losing a line
     * because it was labelled oddly is worse than writing one too many.
     */
    protected function shouldRecord(string $level): bool
    {
        $severity = self::LEVELS[strtolower($level)] ?? null;

        if ($severity === null) {
            return true;
        }

        return $severity >= (self::LEVELS[strtolower($this->level)] ?? 0);
    }

    /**
     * Get the handler this channel writes through.
     */
    public function getHandler(): Handler
    {
        return $this->handler;
    }

    /**
     * Get the lowest severity this channel records.
     */
    public function getLevel(): string
    {
        return $this->level;
    }
}
