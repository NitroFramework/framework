<?php

namespace Nitro\Components;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Encryption\Encrypter;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Mix;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Hashing\HashManager;
use Illuminate\Log\Context\ContextLogProcessor;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Log\LogManager;
use Laravel\SerializableClosure\SerializableClosure;
use Nitro\Foundation\Application;

/**
 * EventServiceProvider, LogServiceProvider, ContextServiceProvider (bindings),
 * EncryptionServiceProvider, CookieServiceProvider, HashServiceProvider, base bindings.
 */
final class Core
{
    public static function events(Application $app): Dispatcher
    {
        return (new Dispatcher($app))
            ->setQueueResolver(static fn () => $app->make(QueueFactory::class))
            ->setTransactionManagerResolver(static fn () => $app->bound('db.transactions') ? $app->make('db.transactions') : null);
    }

    public static function files(): Filesystem
    {
        return new Filesystem;
    }

    public static function log(Application $app): LogManager
    {
        return new LogManager($app);
    }

    public static function context(Application $app): ContextRepository
    {
        return new ContextRepository($app->make('events'));
    }

    public static function contextProcessor(): ContextLogProcessor
    {
        return new ContextLogProcessor;
    }

    public static function packageManifest(Application $app): PackageManifest
    {
        return new PackageManifest(new Filesystem, $app->basePath(), $app->getCachedPackagesPath());
    }

    public static function mix(): Mix
    {
        return new Mix;
    }

    public static function httpKernel(Application $app): \Nitro\Foundation\HttpKernel
    {
        return new \Nitro\Foundation\HttpKernel($app, $app->make('router'));
    }

    public static function consoleKernel(Application $app): \Nitro\Console\Kernel
    {
        return new \Nitro\Console\Kernel($app, $app->make('events'));
    }

    public static function exceptionHandler(Application $app): Handler
    {
        return new Handler($app);
    }

    public static function encrypter(Application $app): Encrypter
    {
        $config = $app->make('config')->get('app');

        return (new Encrypter(self::parseKey($config['key'] ?? null), $config['cipher'] ?? 'AES-256-CBC'))
            ->previousKeys(array_map(self::parseKey(...), $config['previous_keys'] ?? []));
    }

    /**
     * EncryptionServiceProvider::registerSerializableClosureSecurityKey(), run when the
     * SerializableClosure class is first loaded (see Bootstrap).
     */
    public static function configureSerializableClosure(array $appConfig): void
    {
        if (! empty($appConfig['key']) && class_exists(SerializableClosure::class)) {
            SerializableClosure::setSecretKey(self::parseKey($appConfig['key']));
        }
    }

    public static function parseKey(?string $key): string
    {
        if (empty($key)) {
            throw new MissingAppKeyException;
        }

        return str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;
    }

    public static function cookie(Application $app): \Illuminate\Cookie\CookieJar
    {
        $config = $app->make('config')->get('session');

        return (new \Illuminate\Cookie\CookieJar)->setDefaultPathAndDomain(
            $config['path'] ?? '/', $config['domain'] ?? null, $config['secure'] ?? null, $config['same_site'] ?? null
        );
    }

    public static function hash(Application $app): HashManager
    {
        return new HashManager($app);
    }

    public static function hashDriver(Application $app): mixed
    {
        return $app->make('hash')->driver();
    }
}
