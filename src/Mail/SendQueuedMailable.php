<?php

namespace Nitro\Mail;

use Nitro\Queue\Job;
use Throwable;

/**
 * The job that sends a mailable from the queue.
 *
 * The mailable is serialized whole, so the same rule applies to it as
 * to a job's constructor arguments — carry ids, not models.
 */
class SendQueuedMailable extends Job
{
    public function __construct(public Mailable $mailable) {}

    public function handle(): void
    {
        $this->mailable->send(\app(MailManager::class));
    }

    public function queueName(): string
    {
        return $this->mailable->queue ?? 'default';
    }

    public function connectionName(): ?string
    {
        return $this->mailable->connection;
    }

    /** Name the mailable in the failed-jobs table, not this wrapper. */
    public function displayName(): string
    {
        return $this->mailable::class;
    }

    public function failed(Throwable $exception): void
    {
        if (method_exists($this->mailable, 'failed')) {
            $this->mailable->failed($exception);
        }
    }
}
