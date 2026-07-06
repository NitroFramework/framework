<?php

namespace Nitro\Foundation\Providers;

use Nitro\Mail\Contracts\Mailer;
use Nitro\Mail\LogMailer;

/**
 * Registers the mail layer. Ships the log driver by default; bind a different
 * Mailer here (or in an app provider) to send real email.
 */
class MailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Mailer::class, function ($c) {
            $driver = config('mail.driver');

            return match ($driver) {
                // Only the log driver ships today; everything falls back to it
                // rather than failing, so auth flows always have a mailer.
                default => new LogMailer($c->get('paths')->storage('logs/mail.log')),
            };
        });

        $this->container->alias('mailer', Mailer::class);
    }
}
