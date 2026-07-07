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
    ) {}

    public function mailer(?string $name = null): Mailer
    {
        $name ??= $this->config['default'] ?? 'log';

        return $this->mailers[$name] ??= $this->resolve($name);
    }

    protected function resolve(string $name): Mailer
    {
        $config = $this->config['mailers'][$name]
            ?? throw new InvalidArgumentException("Mailer [{$name}] is not configured.");

        return new Mailer($this->createTransport($config), $this->config['from'] ?? null);
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
