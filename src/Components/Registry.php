<?php

namespace Nitro\Components;

/**
 * Static factory table for the services Laravel's base and "replaced" framework providers
 * register (Application::REPLACED_PROVIDERS + events, log, routing).
 *
 * MAP: container key => [factory class, static method, shared]. Nothing here is registered per
 * request; Application::getConcrete() looks keys up on demand, so unused services cost nothing.
 * Any binding registered by the app or a package with the same key wins over the table.
 *
 * Container aliases come from Laravel's own registerCoreContainerAliases(), plus NITRO_ALIASES.
 */
final class Registry
{
    public const MAP = [
        // Base: EventServiceProvider, LogServiceProvider, ContextServiceProvider (bindings)
        'events' => [Core::class, 'events', true],
        'log' => [Core::class, 'log', true],
        'files' => [Core::class, 'files', true],
        \Illuminate\Log\Context\Repository::class => [Core::class, 'context', true],
        \Illuminate\Contracts\Log\ContextLogProcessor::class => [Core::class, 'contextProcessor', false],

        // Application::registerBaseBindings()
        \Illuminate\Foundation\PackageManifest::class => [Core::class, 'packageManifest', true],
        \Illuminate\Foundation\Mix::class => [Core::class, 'mix', true],

        // Kernels (ApplicationBuilder::withKernels() binds the same classes)
        \Illuminate\Contracts\Http\Kernel::class => [Core::class, 'httpKernel', true],
        \Illuminate\Contracts\Console\Kernel::class => [Core::class, 'consoleKernel', true],
        \Illuminate\Contracts\Debug\ExceptionHandler::class => [Core::class, 'exceptionHandler', true],

        // Encryption, cookies, hashing
        'encrypter' => [Core::class, 'encrypter', true],
        'cookie' => [Core::class, 'cookie', true],
        'hash' => [Core::class, 'hash', true],
        'hash.driver' => [Core::class, 'hashDriver', true],

        // RoutingServiceProvider
        'router' => [Http::class, 'router', true],
        'url' => [Http::class, 'url', true],
        'redirect' => [Http::class, 'redirect', true],
        \Illuminate\Contracts\Routing\ResponseFactory::class => [Http::class, 'responseFactory', true],
        \Illuminate\Routing\Contracts\CallableDispatcher::class => [Http::class, 'callableDispatcher', true],
        \Illuminate\Routing\Contracts\ControllerDispatcher::class => [Http::class, 'controllerDispatcher', true],
        \Psr\Http\Message\ServerRequestInterface::class => [Http::class, 'psrRequest', false],
        \Psr\Http\Message\ResponseInterface::class => [Http::class, 'psrResponse', false],

        // SessionServiceProvider
        'session' => [Http::class, 'session', true],
        'session.store' => [Http::class, 'sessionStore', true],
        \Illuminate\Session\Middleware\StartSession::class => [Http::class, 'startSession', true],

        // AuthServiceProvider
        'auth' => [Http::class, 'auth', true],
        'auth.driver' => [Http::class, 'authDriver', true],
        \Illuminate\Contracts\Auth\Authenticatable::class => [Http::class, 'user', false],
        \Illuminate\Contracts\Auth\Access\Gate::class => [Http::class, 'gate', true],
        \Illuminate\Auth\Middleware\RequirePassword::class => [Http::class, 'requirePassword', false],
        'auth.password' => [Http::class, 'passwordBrokerManager', true],
        'auth.password.broker' => [Http::class, 'passwordBroker', false],

        // DatabaseServiceProvider
        'db.factory' => [Database::class, 'factory', true],
        'db' => [Database::class, 'manager', true],
        'db.connection' => [Database::class, 'connection', false],
        'db.schema' => [Database::class, 'schema', false],
        'db.transactions' => [Database::class, 'transactions', true],
        \Illuminate\Contracts\Database\ConcurrencyErrorDetector::class => [Database::class, 'concurrencyDetector', true],
        \Illuminate\Contracts\Database\LostConnectionDetector::class => [Database::class, 'lostConnectionDetector', true],
        \Illuminate\Contracts\Queue\EntityResolver::class => [Database::class, 'entityResolver', true],
        \Faker\Generator::class => [Database::class, 'faker', true],

        // TranslationServiceProvider + ValidationServiceProvider
        'translation.loader' => [Validation::class, 'loader', true],
        'translator' => [Validation::class, 'translator', true],
        'validation.presence' => [Validation::class, 'presence', true],
        'validator' => [Validation::class, 'factory', true],
        \Illuminate\Contracts\Validation\UncompromisedVerifier::class => [Validation::class, 'uncompromisedVerifier', true],

        // CacheServiceProvider
        'cache' => [Cache::class, 'manager', true],
        'cache.store' => [Cache::class, 'store', true],
        'cache.psr6' => [Cache::class, 'psr6', true],
        'memcached.connector' => [Cache::class, 'memcachedConnector', true],
        \Illuminate\Cache\RateLimiter::class => [Cache::class, 'rateLimiter', true],

        // ViewServiceProvider
        'view' => [View::class, 'factory', true],
        'view.finder' => [View::class, 'finder', false],
        'blade.compiler' => [View::class, 'blade', true],
        'view.engine.resolver' => [View::class, 'engines', true],
    ];

    /** Aliases for Nitro's own classes, on top of Laravel's core aliases. */
    public const NITRO_ALIASES = [
        \Nitro\Foundation\Application::class => 'app',
        \Nitro\Routing\Router::class => 'router',
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
