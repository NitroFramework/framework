<?php

namespace Nitro\Notifications;

use Nitro\Queue\Job;

/**
 * The job that delivers a notification implementing ShouldQueue.
 *
 * Email is the usual reason. Handing a message to an SMTP server takes as long
 * as it takes, and a learner who has just passed an assessment should see their
 * result immediately rather than watching a spinner while a mail server is
 * talked to. The pass is recorded in the request; the email leaves a moment
 * later.
 *
 * Both the notifiable and the notification are serialized into the payload, so
 * a notification carrying a whole model graph is a mistake here for the same
 * reason it is on any job: put ids on it, and let the template re-read what it
 * needs when it renders.
 */
class SendQueuedNotification extends Job
{
    public function __construct(
        public object $notifiable,
        public Notification $notification,
    ) {}

    public function handle(): void
    {
        // sendNow, not send: this IS the queued delivery. Going back through
        // send() would look at ShouldQueue again and queue it for ever.
        \app('notification')->sendNow($this->notifiable, $this->notification);
    }

    /**
     * Let a notification pick its queue, so a bulk renewal-reminder run cannot
     * hold up the password reset somebody is waiting on.
     */
    public function queueName(): string
    {
        return method_exists($this->notification, 'queueName')
            ? $this->notification->queueName()
            : 'default';
    }

    public function displayName(): string
    {
        return $this->notification::class;
    }
}
