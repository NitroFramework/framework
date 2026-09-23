<?php

namespace Nitro\Mail;

use InvalidArgumentException;
use Nitro\Mail\Contracts\Transport;
use Nitro\Mail\Transports\ArrayTransport;
use Nitro\Mail\Transports\LogTransport;
use Nitro\Mail\Transports\SmtpTransport;

/**
 * Resolves mailers from config('mail'). Each mailer wraps a transport (log,
 * array, smtp) and the default sender. Calls proxy to the default mailer.
 *
 * @mixin Mailer
 */
class MailManager
{
    /** @var array<string, Mailer> */
    protected array $mailers = [];

    public function __construct(
        protected array $config = [],

        // Optional, so the manager can be built without an application behind
        // it — the mailers it makes then simply raise no events.
        protected ?\Nitro\Events\Dispatcher $events = null,
    ) {}

    public function mailer(?string $name = null): Mailer
    {
        $name ??= $this->config['default'] ?? 'log';

        return $this->mailers[$name] ??= $this->resolve($name);
    }

    /** Begin a message to these recipients. */
    public function to(mixed $users, ?string $name = null): PendingMail
    {
        return (new PendingMail($this))->to($users, $name);
    }

    /** Begin a message copied to these recipients. */
    public function cc(mixed $users, ?string $name = null): PendingMail
    {
        return (new PendingMail($this))->cc($users, $name);
    }

    /** Begin a message blind-copied to these recipients. */
    public function bcc(mixed $users, ?string $name = null): PendingMail
    {
        return (new PendingMail($this))->bcc($users, $name);
    }

    /** Begin a message on a named mailer rather than the default. */
    public function usingMailer(string $name): PendingMail
    {
        return new PendingMail($this, $name);
    }

    /**
     * Put a mailable on the queue rather than sending it now.
     */
    public function queue(Mailable $mailable): void
    {
        SendQueuedMailable::dispatch($mailable)
            ->onConnection($mailable->connection)
            ->onQueue($mailable->queue)
            ->delay($mailable->delay);
    }

    /** Queue a mailable to become eligible after a delay. */
    public function later(int $delay, Mailable $mailable): void
    {
        $this->queue($mailable->delay($delay));
    }

    protected function resolve(string $name): Mailer
    {
        $config = $this->config['mailers'][$name]
            ?? throw new InvalidArgumentException("Mailer [{$name}] is not configured.");

        return new Mailer($this->createTransport($config), $this->config['from'] ?? null, $this->events);
    }

    protected function createTransport(array $config): Transport
    {
        return match ($config['transport'] ?? 'log') {
            'log'   => new LogTransport($config['path'] ?? (function_exists('storage_path') ? storage_path('logs/mail.log') : 'mail.log')),
            'array' => new ArrayTransport(),
            'smtp'  => new SmtpTransport(
                (string) ($config['host'] ?? '127.0.0.1'),
                (int) ($config['port'] ?? 25),
                $config['username'] ?? null,
                $config['password'] ?? null,
                $config['encryption'] ?? null,
                (float) ($config['timeout'] ?? 10.0),
                (string) ($config['local_domain'] ?? 'localhost'),
            ),
            default => throw new InvalidArgumentException("Unsupported mail transport [{$config['transport']}]."),
        };
    }

    public function __call(string $method, array $parameters): mixed
    {
        return $this->mailer()->{$method}(...$parameters);
    }
}
