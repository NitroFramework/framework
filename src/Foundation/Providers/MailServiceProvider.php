<?php

namespace Nitro\Foundation\Providers;

use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Mail\Contracts\Mailer as MailerContract;
use Nitro\Mail\MailManager;
use Nitro\Mail\Mailer;
use Nitro\Mail\Markdown;
use Nitro\View\Contracts\ViewFinder;

/**
 * Register the mail manager, the default mailer and the markdown renderer.
 */
class MailServiceProvider extends ServiceProvider
{
    protected bool $defer = true;

    /** @return array<int, string> */
    public function provides(): array
    {
        return ['mail', MailManager::class, 'mailer', Mailer::class, MailerContract::class, Markdown::class];
    }

    /** Register the mail manager, and the default mailer under 'mailer' and the Mailer contract. */
    public function register(): void
    {
        $this->container->singleton('mail', function ($container) {
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

    /** Register the markdown mail renderer with the configured theme. */
    protected function registerMarkdown(): void
    {
        $this->container->singleton(Markdown::class, function ($container) {
            $config = (array) $container->resolve(ConfigRepository::class)->get('mail', []);

            return new Markdown((string) ($config['markdown']['theme'] ?? 'default'));
        });
    }

    /**
     * Register the mail view namespace.
     *
     * An application overrides a mail layout by placing its own under resources/views/vendor/mail.
     */
    public function boot(): void
    {
        if ($this->container->has(ViewFinder::class)) {
            $this->container->resolve(ViewFinder::class)
                ->addNamespace(Markdown::NAMESPACE, __DIR__ . '/../../Mail/views');
        }
    }
}
