<?php

namespace Nitro\Foundation\Providers;

use Nitro\Mail\Contracts\Mailer as MailerContract;
use Nitro\Mail\MailManager;
use Nitro\Mail\Mailer;

/**
 * Registers the mail layer: a MailManager ('mail') that resolves mailers from
 * config('mail'), and the default mailer bound to 'mailer' and the Mailer
 * contract so app(Mailer::class) and the Mail facade both work.
 */
class MailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton('mail', function ($container) {
            // The event bus goes in, so a message can be logged, redirected or
            // stamped without anything having to wrap the mailer.
            return new MailManager(
                (array) config('mail', []),
                $container->createOrResolve('events'),
            );
        });
        $this->container->alias(MailManager::class, 'mail');

        $this->container->singleton('mailer', function ($container) {
            return $container->createOrResolve('mail')->mailer();
        });
        $this->container->alias(Mailer::class, 'mailer');
        $this->container->alias(MailerContract::class, 'mailer');
    }
}
