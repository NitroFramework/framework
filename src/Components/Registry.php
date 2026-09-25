<?php

namespace Nitro\Components;

use Faker\Generator;
use Illuminate\Auth\AuthServiceProvider;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Contracts\Database\LostConnectionDetector;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Contracts\Log\ContextLogProcessor;
use Illuminate\Contracts\Queue\EntityResolver;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Cookie\CookieServiceProvider;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Foundation\Mix;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Hashing\HashServiceProvider;
use Illuminate\Log\Context\Repository;
use Illuminate\Routing\Contracts\CallableDispatcher;
use Illuminate\Routing\Contracts\ControllerDispatcher;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionServiceProvider;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\Validation\ValidationServiceProvider;
use Illuminate\View\ViewServiceProvider;
use Nitro\Foundation\Application;
use Nitro\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The component registry: the core services, built on demand from static factories.
 *
 * MAP: container key => [factory class, static method, shared]. Nothing here is registered per
 * request; Application::getConcrete() looks keys up on demand, so unused services cost nothing.
 * Any binding registered by the app or a package with the same key wins over the table.
 *
 * PROVIDERS: the component providers, providers whose services are components. They are skipped
 * at boot, since the table already builds everything they would register.
 *
 * Container aliases are the framework's core aliases, plus NITRO_ALIASES.
 */
final class Registry
{
    /**
     * Component providers: providers whose services are components.
     */
    public const PROVIDERS = [
        AuthServiceProvider::class,
        CacheServiceProvider::class,
        CookieServiceProvider::class,
        DatabaseServiceProvider::class,
        EncryptionServiceProvider::class,
        HashServiceProvider::class,
        SessionServiceProvider::class,
        TranslationServiceProvider::class,
        ValidationServiceProvider::class,
        ViewServiceProvider::class,
    ];

    /**
     * Container key => [factory class, static method, shared].
     */
    public const MAP = [
        /** Base: EventServiceProvider, LogServiceProvider, ContextServiceProvider (bindings) */
        'events' => [Core::class, 'events', true],
        'log' => [Core::class, 'log', true],
        'files' => [Core::class, 'files', true],
        Repository::class => [Core::class, 'context', true],
        ContextLogProcessor::class => [Core::class, 'contextProcessor', false],

        /** Application::registerBaseBindings() */
        PackageManifest::class => [Core::class, 'packageManifest', true],
        Mix::class => [Core::class, 'mix', true],

        /** Kernels (ApplicationBuilder::withKernels() binds the same classes) */
        HttpKernelContract::class => [Core::class, 'httpKernel', true],
        ConsoleKernelContract::class => [Core::class, 'consoleKernel', true],
        ExceptionHandler::class => [Core::class, 'exceptionHandler', true],

        /** Encryption, cookies, hashing */
        'encrypter' => [Core::class, 'encrypter', true],
        'cookie' => [Core::class, 'cookie', true],
        'hash' => [Core::class, 'hash', true],
        'hash.driver' => [Core::class, 'hashDriver', true],

        /** RoutingServiceProvider */
        'router' => [Http::class, 'router', true],
        'url' => [Http::class, 'url', true],
        'redirect' => [Http::class, 'redirect', true],
        ResponseFactory::class => [Http::class, 'responseFactory', true],
        CallableDispatcher::class => [Http::class, 'callableDispatcher', true],
        ControllerDispatcher::class => [Http::class, 'controllerDispatcher', true],
        ServerRequestInterface::class => [Http::class, 'psrRequest', false],
        ResponseInterface::class => [Http::class, 'psrResponse', false],

        /** SessionServiceProvider */
        'session' => [Http::class, 'session', true],
        'session.store' => [Http::class, 'sessionStore', true],
        StartSession::class => [Http::class, 'startSession', true],

        /** AuthServiceProvider */
        'auth' => [Http::class, 'auth', true],
        'auth.driver' => [Http::class, 'authDriver', true],
        Authenticatable::class => [Http::class, 'user', false],
        Gate::class => [Http::class, 'gate', true],
        RequirePassword::class => [Http::class, 'requirePassword', false],
        'auth.password' => [Http::class, 'passwordBrokerManager', true],
        'auth.password.broker' => [Http::class, 'passwordBroker', false],

        /** DatabaseServiceProvider */
        'db.factory' => [Database::class, 'factory', true],
        'db' => [Database::class, 'manager', true],
        'db.connection' => [Database::class, 'connection', false],
        'db.schema' => [Database::class, 'schema', false],
        'db.transactions' => [Database::class, 'transactions', true],
        ConcurrencyErrorDetector::class => [Database::class, 'concurrencyDetector', true],
        LostConnectionDetector::class => [Database::class, 'lostConnectionDetector', true],
        EntityResolver::class => [Database::class, 'entityResolver', true],
        Generator::class => [Database::class, 'faker', true],

        /** TranslationServiceProvider + ValidationServiceProvider */
        'translation.loader' => [Validation::class, 'loader', true],
        'translator' => [Validation::class, 'translator', true],
        'validation.presence' => [Validation::class, 'presence', true],
        'validator' => [Validation::class, 'factory', true],
        UncompromisedVerifier::class => [Validation::class, 'uncompromisedVerifier', true],

        /** CacheServiceProvider */
        'cache' => [Cache::class, 'manager', true],
        'cache.store' => [Cache::class, 'store', true],
        'cache.psr6' => [Cache::class, 'psr6', true],
        'memcached.connector' => [Cache::class, 'memcachedConnector', true],
        RateLimiter::class => [Cache::class, 'rateLimiter', true],

        /** ViewServiceProvider */
        'view' => [View::class, 'factory', true],
        'view.finder' => [View::class, 'finder', false],
        'blade.compiler' => [View::class, 'blade', true],
        'view.engine.resolver' => [View::class, 'engines', true],
    ];

    /** Aliases for Nitro's own classes, on top of Laravel's core aliases. */
    public const NITRO_ALIASES = [
        Application::class => 'app',
        Router::class => 'router',
    ];

    /** @var array{0: array<string, string>, 1: array<string, string[]>}|null */
    private static ?array $aliasTables = null;

    /**
     * Laravel's core alias tables (registerCoreContainerAliases) + Nitro's, computed once per
     * process and copied into each Application.
     *
     * @return array{0: array<string, string>, 1: array<string, string[]>}
     */
    public static function aliasTables(callable $registerCoreAliases): array
    {
        if (self::$aliasTables === null) {
            [$aliases, $abstractAliases] = $registerCoreAliases();

            foreach (self::NITRO_ALIASES as $alias => $abstract) {
                $aliases[$alias] = $abstract;
                $abstractAliases[$abstract][] = $alias;
            }

            self::$aliasTables = [$aliases, $abstractAliases];
        }

        return self::$aliasTables;
    }
}
