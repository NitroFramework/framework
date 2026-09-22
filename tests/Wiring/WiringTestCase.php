<?php

namespace Tests\Wiring;

use Nitro\Container\Container;
use Nitro\Foundation\Application;
use PHPUnit\Framework\TestCase;

/**
 * A booted application, for the tests that check what the container hands out.
 *
 * Every other suite here builds its subject directly — `new CacheManager($config)`,
 * `new Repository(new ArrayStore())` — which tests the class and skips the wiring.
 * Two bugs lived in that gap: a cache repository that only got its event bus if
 * something had already resolved a different binding, and a Pipeline bound shared
 * while its own comment explained why it must not be. Both were invisible to a
 * suite that never asked the container for anything.
 *
 * These tests are driven off the container's own registry rather than a hand-kept
 * list, so a layer added later is covered the day it registers a binding.
 */
abstract class WiringTestCase extends TestCase
{
    /**
     * Needs a live request. The binding is correct; there is no request here.
     *
     * @var array<string, string> name => why
     */
    protected const REQUEST_SCOPED = [
        'request'                         => 'bound by the kernel per request',
        'Nitro\Http\Request'              => 'as above',
        'session'                         => 'started by the StartSession middleware',
        'Nitro\Session\Contracts\Session' => 'as above',
        'Nitro\Session\Store'             => 'as above',
        'auth'                            => 'guard for the current request; needs a session',
        'Nitro\Auth\SessionGuard'         => 'as above',
        'Nitro\Auth\Contracts\Guard'      => 'as above',
        'cookie'                          => 'jar for the current request',
        'Nitro\Cookie\CookieJar'          => 'as above',
    ];

    /**
     * Needs configuration or a service an application supplies. This repo is
     * the framework, not an app: there is no APP_KEY, no App\Models\User, no
     * configured disk, mailer or database.
     *
     * A name belongs here only when resolving it fails with a message that
     * names the missing configuration. Anything failing for another reason is
     * a wiring bug and must not be listed.
     *
     * @var array<string, string> name => what an application would supply
     */
    protected const NEEDS_APPLICATION_CONFIG = [
        'encrypter'                             => 'app.key',
        'Nitro\Encryption\Encrypter'            => 'app.key',
        'Nitro\Encryption\Contracts\Encrypter'  => 'app.key',
        'auth.password'                         => 'auth.model',
        'Nitro\Auth\Contracts\UserProvider'     => 'auth.model',
        'Nitro\Auth\Passwords\PasswordBroker'   => 'auth.model',
        'Nitro\Filesystem\Contracts\Filesystem' => 'a configured disk',
        'mailer'                                => 'a configured mailer',
        'Nitro\Mail\Mailer'                     => 'a configured mailer',
        'Nitro\Mail\Contracts\Mailer'           => 'a configured mailer',
        'redis'                                 => 'a running redis server',
        'Nitro\Redis\RedisManager'              => 'a running redis server',
        'db'                                    => 'a database connection',
        'Nitro\Database\Connection'             => 'a database connection',
    ];

    protected Application $app;
    protected Container $container;

    /**
     * Booting installs handlers this test has to put back, or PHPUnit calls
     * the test risky. Each application installs one error handler
     * (HandleExceptions) and two exception handlers — Application::create()
     * sets a fatal reporter before the bootstrapper sets the real one.
     */
    private int $booted = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app       = $this->freshApplication();
        $this->container = $this->app->getContainer();
    }

    protected function tearDown(): void
    {
        while ($this->booted-- > 0) {
            restore_error_handler();
            restore_exception_handler();
            restore_exception_handler();
        }

        Container::setInstance(new Container());

        parent::tearDown();
    }

    /** A fresh application, for checking that resolution order changes nothing. */
    protected function freshApplication(): Application
    {
        Container::setInstance(new Container());

        $app = Application::create(dirname(__DIR__, 2));
        $app->bootstrap();

        $this->booted++;

        return $app;
    }

    protected function isSkipped(string $name): bool
    {
        return isset(static::REQUEST_SCOPED[$name])
            || isset(static::NEEDS_APPLICATION_CONFIG[$name]);
    }

    /**
     * Every name the container can be asked for: registered bindings plus the
     * services deferred providers promise.
     *
     * @return array<int, string>
     */
    protected function everyServiceName(): array
    {
        return array_values(array_unique(array_merge(
            $this->container->getServiceNames(),
            array_keys($this->app->getDeferredServices()),
        )));
    }
}
