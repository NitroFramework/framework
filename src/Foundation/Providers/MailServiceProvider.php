<?php

namespace Nitro\Foundation\Providers;

use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Mail\Contracts\Mailer as MailerContract;
use Nitro\Mail\MailManager;
use Nitro\Mail\Mailer;
use Nitro\Mail\Markdown;
use Nitro\View\Contracts\ViewFinder;

/**
 * Registers the mail layer: a MailManager ('mail') that resolves mailers from
 * config('mail'), and the default mailer bound to 'mailer' and the Mailer
 * contract so app(Mailer::class) and the Mail facade both work.
 */
class MailServiceProvider extends ServiceProvider
{
    protected bool $defer = true;

    /** @return array<int, string> */
    public function provides(): array
    {
        return ['mail', MailManager::class, 'mailer', Mailer::class, MailerContract::class, Markdown::class];
    }

    public function register(): void
    {
        $this->container->singleton('mail', function ($container) {
            // The event bus goes in, so a message can be logged, redirected or
            // stamped without anything having to wrap the mailer.
            return new MailManager(
                (array) $container->resolve(ConfigRepository::class)->get('mail', []),
                $container->resolve('events'),
            );
        });
        $this->container->alias('mail', MailManager::class);

        $this->container->singleton('mailer', function ($container) {
            return $container->resolve('mail')->mailer();
        });
        $this->container->alias('mailer', Mailer::class);
        $this->container->alias('mailer', MailerContract::class);

        $this->registerMarkdown();
    }

    /** What renders a markdown mail view, and where it finds its layout. */
    protected function registerMarkdown(): void
    {
        $this->container->singleton(Markdown::class, function ($container) {
            $config = (array) $container->resolve(ConfigRepository::class)->get('mail', []);

            return new Markdown((string) ($config['markdown']['theme'] ?? 'default'));
        });
    }

    public function boot(): void
    {
        // An application overrides the layout by putting its own under
        // resources/views/vendor/mail, so there is nothing to publish.
        if ($this->container->has(ViewFinder::class)) {
            $this->container->resolve(ViewFinder::class)
                ->addNamespace(Markdown::NAMESPACE, __DIR__ . '/../../Mail/views');
        }
    }
}
