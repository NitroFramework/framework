<?php

namespace Nitro\Components;

use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Factory;
use Nitro\Foundation\Application;

/**
 * TranslationServiceProvider + ValidationServiceProvider, wired directly.
 */
final class Validation
{
    private static ?string $frameworkLangPath = null;

    public static function loader(Application $app): FileLoader
    {
        return new FileLoader($app->make('files'), [self::frameworkLangPath(), $app->langPath()]);
    }

    public static function translator(Application $app): Translator
    {
        $translator = new Translator($app->make('translation.loader'), $app->getLocale() ?? 'en');
        $translator->setFallback($app->getFallbackLocale() ?? 'en');

        return $translator;
    }

    public static function presence(Application $app): DatabasePresenceVerifier
    {
        return new DatabasePresenceVerifier($app->make('db'));
    }

    public static function factory(Application $app): Factory
    {
        $validator = new Factory($app->make('translator'), $app);

        if ($app->make('config')->get('database.default') !== null) {
            $validator->setPresenceVerifier($app->make('validation.presence'));
        }

        return $validator;
    }

    public static function uncompromisedVerifier(Application $app): \Illuminate\Validation\NotPwnedVerifier
    {
        return new \Illuminate\Validation\NotPwnedVerifier($app->make(\Illuminate\Http\Client\Factory::class));
    }

    /**
     * The default English messages ship inside illuminate/translation (or laravel/framework when
     * it replaces the split packages). Located via Composer's class map, once per process.
     */
    private static function frameworkLangPath(): string
    {
        if (self::$frameworkLangPath === null) {
            $file = null;

            foreach (\Composer\Autoload\ClassLoader::getRegisteredLoaders() as $loader) {
                if ($file = $loader->findFile(Translator::class)) {
                    break;
                }
            }

            self::$frameworkLangPath = dirname((string) realpath((string) $file)).DIRECTORY_SEPARATOR.'lang';
        }

        return self::$frameworkLangPath;
    }
}
