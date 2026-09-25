<?php

namespace Nitro\Foundation;

use Nitro\Broadcasting\BroadcastServiceProvider;
use Nitro\Cache\CacheServiceProvider;
use Nitro\Concurrency\ConcurrencyServiceProvider;
use Nitro\Context\ContextServiceProvider;
use Nitro\Cookie\CookieServiceProvider;
use Nitro\Encryption\EncryptionServiceProvider;
use Nitro\Filesystem\FilesystemServiceProvider;
use Nitro\Foundation\Providers\AuthServiceProvider;
use Nitro\Foundation\Providers\ConsoleServiceProvider;
use Nitro\Foundation\Providers\DatabaseServiceProvider;
use Nitro\Foundation\Providers\ExceptionServiceProvider;
use Nitro\Foundation\Providers\MailServiceProvider;
use Nitro\Foundation\Providers\RoutingServiceProvider;
use Nitro\Foundation\Providers\ValidationServiceProvider;
use Nitro\Foundation\Providers\ViewServiceProvider;
use Nitro\Http\HttpServiceProvider;
use Nitro\Inertia\InertiaServiceProvider;
use Nitro\Log\LogServiceProvider;
use Nitro\Notifications\NotificationServiceProvider;
use Nitro\Process\ProcessServiceProvider;
use Nitro\Queue\QueueServiceProvider;
use Nitro\Redis\RedisServiceProvider;
use Nitro\Scheduling\ScheduleServiceProvider;
use Nitro\Session\SessionServiceProvider;
use Nitro\Support\SupportServiceProvider;
use Nitro\Testing\TestingServiceProvider;
use Nitro\Translation\TranslationServiceProvider;

/**
 * The service providers the framework registers for every application.
 */
final class DefaultProviders
{
    /**
     * Get the provider classes, in registration order.
     *
     * @return array<int, class-string>
     */
    public function toArray(): array
    {
        return [
            LogServiceProvider::class,
            RoutingServiceProvider::class,
            SessionServiceProvider::class,
            ViewServiceProvider::class,
            DatabaseServiceProvider::class,
            ExceptionServiceProvider::class,
            EncryptionServiceProvider::class,
            CookieServiceProvider::class,
            ValidationServiceProvider::class,
            MailServiceProvider::class,
            NotificationServiceProvider::class,
            AuthServiceProvider::class,
            ConsoleServiceProvider::class,
            CacheServiceProvider::class,
            ConcurrencyServiceProvider::class,
            FilesystemServiceProvider::class,
            RedisServiceProvider::class,
            QueueServiceProvider::class,
            ScheduleServiceProvider::class,
            SupportServiceProvider::class,
            HttpServiceProvider::class,
            ContextServiceProvider::class,
            ProcessServiceProvider::class,
            TranslationServiceProvider::class,
            BroadcastServiceProvider::class,
            TestingServiceProvider::class,
            InertiaServiceProvider::class,
        ];
    }
}
