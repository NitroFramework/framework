<?php

namespace Nitro\Queue;

/**
 * Why a worker process exited.
 *
 * A supervisor restarts a worker whatever the reason, so the exit code
 * alone says little; this is what distinguishes a deliberate --max-jobs
 * recycle from a job that froze and had to be killed.
 */
enum WorkerStopReason: string
{
    case Interrupted = 'interrupted';
    case LostConnection = 'lost_connection';
    case MaxJobsExceeded = 'max_jobs';
    case MaxMemoryExceeded = 'memory';
    case MaxTimeExceeded = 'max_time';
    case QueueEmpty = 'empty';
    case QueueEmptyFor = 'empty_for';
    case ReceivedRestartSignal = 'restart_signal';
    case TimedOut = 'timed_out';

    public function description(): string
    {
        return match ($this) {
            self::Interrupted => 'Interrupted',
            self::LostConnection => 'Lost connection',
            self::MaxJobsExceeded => 'Maximum jobs exceeded',
            self::MaxMemoryExceeded => 'Memory limit exceeded',
            self::MaxTimeExceeded => 'Maximum run time exceeded',
            self::QueueEmpty => 'Queue empty',
            self::QueueEmptyFor => 'Queue empty for the configured duration',
            self::ReceivedRestartSignal => 'Received restart signal',
            self::TimedOut => 'Job timed out',
        };
    }
}
