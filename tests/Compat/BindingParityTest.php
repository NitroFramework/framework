<?php

namespace Nitro\Tests\Compat;

use Nitro\Tests\TestCase;

/**
 * Container keys + aliases must match Laravel's Application::registerCoreContainerAliases(),
 * so type-hinting any contract or core class works the same as in Laravel.
 *
 * LARAVEL is copied from laravel/framework v13.33. When laravel/framework is installed next to
 * Nitro, the live list is compared instead, so upstream additions fail this test on upgrade.
 */
class BindingParityTest extends TestCase
{
    private const LARAVEL = [
        'app' => [\Illuminate\Contracts\Container\Container::class, \Illuminate\Contracts\Foundation\Application::class, \Psr\Container\ContainerInterface::class],
        'auth' => [\Illuminate\Auth\AuthManager::class, \Illuminate\Contracts\Auth\Factory::class],
        'auth.driver' => [\Illuminate\Contracts\Auth\Guard::class],
        'auth.password' => [\Illuminate\Auth\Passwords\PasswordBrokerManager::class, \Illuminate\Contracts\Auth\PasswordBrokerFactory::class],
        'auth.password.broker' => [\Illuminate\Auth\Passwords\PasswordBroker::class, \Illuminate\Contracts\Auth\PasswordBroker::class],
        'blade.compiler' => [\Illuminate\View\Compilers\BladeCompiler::class],
        'cache' => [\Illuminate\Cache\CacheManager::class, \Illuminate\Contracts\Cache\Factory::class],
        'cache.store' => [\Illuminate\Cache\Repository::class, \Illuminate\Contracts\Cache\Repository::class, \Psr\SimpleCache\CacheInterface::class],
        'cache.psr6' => [\Symfony\Component\Cache\Adapter\Psr16Adapter::class, \Symfony\Component\Cache\Adapter\AdapterInterface::class, \Psr\Cache\CacheItemPoolInterface::class],
        'config' => [\Illuminate\Config\Repository::class, \Illuminate\Contracts\Config\Repository::class],
        'cookie' => [\Illuminate\Cookie\CookieJar::class, \Illuminate\Contracts\Cookie\Factory::class, \Illuminate\Contracts\Cookie\QueueingFactory::class],
        'db' => [\Illuminate\Database\DatabaseManager::class, \Illuminate\Database\ConnectionResolverInterface::class],
        'db.connection' => [\Illuminate\Database\Connection::class, \Illuminate\Database\ConnectionInterface::class],
        'db.schema' => [\Illuminate\Database\Schema\Builder::class],
        'encrypter' => [\Illuminate\Encryption\Encrypter::class, \Illuminate\Contracts\Encryption\Encrypter::class, \Illuminate\Contracts\Encryption\StringEncrypter::class],
        'events' => [\Illuminate\Events\Dispatcher::class, \Illuminate\Contracts\Events\Dispatcher::class],
        'files' => [\Illuminate\Filesystem\Filesystem::class],
        'filesystem' => [\Illuminate\Filesystem\FilesystemManager::class, \Illuminate\Contracts\Filesystem\Factory::class],
        'filesystem.disk' => [\Illuminate\Contracts\Filesystem\Filesystem::class],
        'filesystem.cloud' => [\Illuminate\Contracts\Filesystem\Cloud::class],
        'hash' => [\Illuminate\Hashing\HashManager::class],
        'hash.driver' => [\Illuminate\Contracts\Hashing\Hasher::class],
        'log' => [\Illuminate\Log\LogManager::class, \Psr\Log\LoggerInterface::class],
        'mail.manager' => [\Illuminate\Mail\MailManager::class, \Illuminate\Contracts\Mail\Factory::class],
        'mailer' => [\Illuminate\Mail\Mailer::class, \Illuminate\Contracts\Mail\Mailer::class, \Illuminate\Contracts\Mail\MailQueue::class],
        'queue' => [\Illuminate\Queue\QueueManager::class, \Illuminate\Contracts\Queue\Factory::class, \Illuminate\Contracts\Queue\Monitor::class],
        'queue.connection' => [\Illuminate\Contracts\Queue\Queue::class],
        'queue.failer' => [\Illuminate\Queue\Failed\FailedJobProviderInterface::class],
        'redirect' => [\Illuminate\Routing\Redirector::class],
        'redis' => [\Illuminate\Redis\RedisManager::class, \Illuminate\Contracts\Redis\Factory::class],
        'redis.connection' => [\Illuminate\Redis\Connections\Connection::class, \Illuminate\Contracts\Redis\Connection::class],
        'request' => [\Illuminate\Http\Request::class, \Symfony\Component\HttpFoundation\Request::class],
        'router' => [\Illuminate\Routing\Router::class, \Illuminate\Contracts\Routing\Registrar::class, \Illuminate\Contracts\Routing\BindingRegistrar::class],
        'session' => [\Illuminate\Session\SessionManager::class],
        'session.store' => [\Illuminate\Session\Store::class, \Illuminate\Contracts\Session\Session::class],
        'translator' => [\Illuminate\Translation\Translator::class, \Illuminate\Contracts\Translation\Translator::class],
        'url' => [\Illuminate\Routing\UrlGenerator::class, \Illuminate\Contracts\Routing\UrlGenerator::class],
        'validator' => [\Illuminate\Validation\Factory::class, \Illuminate\Contracts\Validation\Factory::class],
        'view' => [\Illuminate\View\Factory::class, \Illuminate\Contracts\View\Factory::class],
    ];

    /** Keys that need an optional package (not a dependency of nitro/framework): key => class it needs. */
    private const OPTIONAL = [
        'cache.psr6' => \Symfony\Component\Cache\Adapter\Psr16Adapter::class,
        'mail.manager' => \Illuminate\Mail\MailManager::class,
        'mailer' => \Illuminate\Mail\Mailer::class,
        'redis' => \Illuminate\Redis\RedisManager::class,
        'redis.connection' => \Illuminate\Redis\RedisManager::class,
        'filesystem.disk' => \League\Flysystem\Filesystem::class,
        'filesystem.cloud' => \League\Flysystem\Filesystem::class,
    ];

    public function test_every_laravel_alias_points_at_the_same_key(): void
    {
        $app = $this->boot();

        foreach (self::expected() as $key => $aliases) {
            foreach ($aliases as $alias) {
                if ($alias === 'Illuminate\Foundation\Application') {
                    continue; // Nitro's Application is a different class; contracts cover it.
                }

                $this->assertSame($key, $app->getAlias($alias), "{$alias} should alias [{$key}]");
            }
        }
    }

    public function test_every_laravel_key_is_bound(): void
    {
        $app = $this->boot();

        foreach (array_keys(self::expected()) as $key) {
            $this->assertTrue($app->bound($key) || $key === 'request', "[{$key}] is not bound");
        }
    }

    public function test_every_installable_key_resolves_to_the_expected_type(): void
    {
        $app = $this->boot();
        $app->instance('request', \Illuminate\Http\Request::create('/'));

        foreach (self::expected() as $key => $aliases) {
            if (isset(self::OPTIONAL[$key]) && ! class_exists(self::OPTIONAL[$key])) {
                continue;
            }

            // These open a live connection on resolution (redis server, database file).
            if (in_array($key, ['redis.connection', 'db.connection', 'db.schema'], true)) {
                continue;
            }

            $service = $app->make($key);

            foreach ($aliases as $alias) {
                if (interface_exists($alias) || class_exists($alias)) {
                    if ($alias === 'Illuminate\Foundation\Application') {
                        continue;
                    }

                    $this->assertInstanceOf($alias, $service, "[{$key}] must satisfy {$alias}");
                }
            }
        }
    }


    private static function expected(): array
    {
        if (! class_exists($class = 'Illuminate\Foundation\Application')) {
            return self::LARAVEL;
        }

        // laravel/framework installed alongside: read the live alias list.
        $laravel = new $class(sys_get_temp_dir());
        $expected = [];

        foreach ((fn () => $this->abstractAliases)->call($laravel) as $key => $aliases) {
            $expected[$key] = array_values($aliases);
        }

        \Illuminate\Container\Container::setInstance(null);

        return $expected;
    }

}
