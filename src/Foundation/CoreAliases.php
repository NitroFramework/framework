<?php

namespace Nitro\Foundation;

use Nitro\Auth\Access\Gate;
use Nitro\Auth\Passwords\PasswordBroker;
use Nitro\Broadcasting\BroadcastManager;
use Nitro\Cache\RateLimiter;
use Nitro\Console\Kernel as ConsoleKernel;
use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Context\Repository as ContextRepository;
use Nitro\Http\Redirector;
use Nitro\Http\ResponseFactory;
use Nitro\Process\Factory as ProcessFactory;
use Nitro\Support\DateFactory;
use Nitro\Support\Hash;
use Nitro\Support\Pipeline;
use Nitro\Testing\ParallelTesting;
use Nitro\Translation\Translator;
use Nitro\View\Blade;

/**
 * The short names the facades resolve through.
 *
 * Names only: each service is built by its own provider.
 */
final class CoreAliases
{
    /** @var array<string, class-string> */
    private const ALIASES = [
        'blade'            => Blade::class,
        'gate'             => Gate::class,
        'hash'             => Hash::class,
        'date'             => DateFactory::class,
        'response'         => ResponseFactory::class,
        'redirect'         => Redirector::class,
        'rate.limiter'     => RateLimiter::class,
        'auth.password'    => PasswordBroker::class,
        'artisan'          => ConsoleKernel::class,
        'context'          => ContextRepository::class,
        'process'          => ProcessFactory::class,
        'parallel.testing' => ParallelTesting::class,
        'translator'       => Translator::class,
        'broadcast'        => BroadcastManager::class,
        'pipeline'         => Pipeline::class,
        'maintenance'      => MaintenanceMode::class,
    ];

    /**
     * Point each short name at its class.
     *
     * The class is always the binding and the name the alias; the reverse would loop.
     */
    public static function register(ContainerInterface $container): void
    {
        foreach (self::ALIASES as $name => $class) {
            $container->alias($class, $name);
        }
    }
}
