<?php

namespace Nitro\Mail;

/**
 * The recipients collected before a mailable is named.
 *
 *     Mail::to($user)->cc($manager)->send(new OrderShipped($order));
 *
 * Reads in the order the sentence does — who it goes to, then what it
 * is — which is why the mailable arrives last.
 */
class PendingMail
{
    /** @var array<int, mixed> */
    protected array $to = [];

    /** @var array<int, mixed> */
    protected array $cc = [];

    /** @var array<int, mixed> */
    protected array $bcc = [];

    protected ?string $locale = null;

    public function __construct(protected MailManager $manager, protected ?string $mailer = null) {}

    public function to(mixed $users, ?string $name = null): static
    {
        $this->to[] = [$users, $name];

        return $this;
    }

    public function cc(mixed $users, ?string $name = null): static
    {
        $this->cc[] = [$users, $name];

        return $this;
    }

    public function bcc(mixed $users, ?string $name = null): static
    {
        $this->bcc[] = [$users, $name];

        return $this;
    }

    public function locale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    /**
     * Send the mailable, or queue it when it asks to be queued.
     */
    public function send(Mailable $mailable): void
    {
        if ($mailable->shouldQueue()) {
            $this->queue($mailable);

            return;
        }

        $this->sendNow($mailable);
    }

    /** Send it in this process, whatever it asked for. */
    public function sendNow(Mailable $mailable): void
    {
        $this->fill($mailable)->send($this->manager);
    }

    /** Put it on the queue, whatever it asked for. */
    public function queue(Mailable $mailable): void
    {
        $mailable = $this->fill($mailable);

        SendQueuedMailable::dispatch($mailable)
            ->onConnection($mailable->connection)
            ->onQueue($mailable->queue)
            ->delay($mailable->delay);
    }

    /** Queue it to become eligible after a delay. */
    public function later(int $delay, Mailable $mailable): void
    {
        $this->queue($mailable->delay($delay));
    }

    /** Hand the collected recipients to the mailable. */
    protected function fill(Mailable $mailable): Mailable
    {
        foreach ($this->to as [$users, $name]) {
            $mailable->to($users, $name);
        }

        foreach ($this->cc as [$users, $name]) {
            $mailable->cc($users, $name);
        }

        foreach ($this->bcc as [$users, $name]) {
            $mailable->bcc($users, $name);
        }

        if ($this->mailer !== null) {
            $mailable->mailer($this->mailer);
        }

        if ($this->locale !== null) {
            $mailable->locale($this->locale);
        }

        return $mailable;
    }
}
