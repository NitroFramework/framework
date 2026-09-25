<?php

namespace Nitro\Components;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Cookie\CookieJar;
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
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Context;
use Laravel\SerializableClosure\SerializableClosure;
use Nitro\Console\Kernel;
use Nitro\Foundation\Application;
use Nitro\Foundation\HttpKernel;

/**
 * EventServiceProvider, LogServiceProvider, ContextServiceProvider, EncryptionServiceProvider,
 * CookieServiceProvider, HashServiceProvider, base bindings.
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

    /**
     * The context repository; in console processes started with __LARAVEL_CONTEXT (e.g. by the
     * Concurrency process driver), it starts hydrated from that payload.
     */
    public static function context(Application $app): ContextRepository
    {
        $repository = new ContextRepository($app->make('events'));

        if ($app->runningInConsole()
            && ($context = Env::get('__LARAVEL_CONTEXT'))
            && ($context = json_decode($context, associative: true))) {
            $repository->hydrate($context);
        }

        return $repository;
    }

    /**
     * Queued job payloads carry the current context. Registered when the queue base class first
     * loads, so requests that never queue anything never pay for it.
     */
    public static function carryContextInPayloads(): void
    {
        Queue::createPayloadUsing(static function ($connection, $queue, $payload) {
            $context = Context::dehydrate();

            return $context === null ? $payload : [
                ...$payload,
                'illuminate:log:context' => $context,
            ];
        });
    }

    /**
     * A job being processed restores the context it was queued with. Registered when the
     * JobProcessing event class first loads (the sync queue and the worker both construct it).
     */
    public static function hydrateContextForJobs(Application $app): void
    {
        $app->make('events')->listen(JobProcessing::class, static function (JobProcessing $event) {
            Context::hydrate($event->job->payload()['illuminate:log:context'] ?? null);
        });
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

    public static function httpKernel(Application $app): HttpKernel
    {
        return new HttpKernel($app, $app->make('router'));
    }

    public static function consoleKernel(Application $app): Kernel
    {
        return new Kernel($app, $app->make('events'));
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

    public static function cookie(Application $app): CookieJar
    {
        $config = $app->make('config')->get('session');

        return (new CookieJar)->setDefaultPathAndDomain(
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
