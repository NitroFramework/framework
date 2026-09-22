<?php

namespace Nitro\Translation;

use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\Contracts\PathRegistry;
use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Binds the translator against the application's lang directory and configured
 * locale.
 *
 * Built from config rather than autowired, which is why it was in the
 * Application: the composition root was the only place that had both the path
 * registry and the config to hand. A provider has the container, so it has both.
 */
class TranslationServiceProvider extends ServiceProvider
{
    protected bool $defer = true;

    /** @return array<int, string> */
    public function provides(): array
    {
        return [Translator::class];
    }

    public function register(): void
    {
        $this->container->singleton(Translator::class, function ($container) {
            $paths  = $container->resolve(PathRegistry::class);
            $config = $container->resolve(ConfigRepository::class);

            return new Translator(
                $paths->base('lang'),
                (string) $config->get('app.locale', 'en'),
                (string) $config->get('app.fallback_locale', 'en'),
            );
        });
    }
}
