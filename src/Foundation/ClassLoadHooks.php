<?php

namespace Nitro\Foundation;

use Composer\Autoload\ClassLoader;

/**
 * Run a callback right after a class is first autoloaded. Lets bootstrap wiring that Laravel
 * performs eagerly (Eloquent's connection resolver, the closure signing key) happen only when
 * the class is actually used, so requests that never touch a model load none of Eloquent.
 *
 * One prepended autoloader serves all hooks and unregisters itself when none remain.
 */
final class ClassLoadHooks
{
    /** @var array<class-string, list<callable>> */
    private static array $hooks = [];

    private static bool $registered = false;

    public static function after(string $class, callable $callback): void
    {
        if (class_exists($class, false)) {
            $callback();

            return;
        }

        self::$hooks[$class][] = $callback;

        if (! self::$registered) {
            spl_autoload_register([self::class, 'load'], true, true);
            self::$registered = true;
        }
    }

    public static function load(string $class): void
    {
        if (! isset(self::$hooks[$class])) {
            return;
        }

        $callbacks = self::$hooks[$class];
        unset(self::$hooks[$class]);

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            if ($loader->loadClass($class)) {
                break;
            }
        }

        if (self::$hooks === []) {
            spl_autoload_unregister([self::class, 'load']);
            self::$registered = false;
        }

        if (class_exists($class, false)) {
            foreach ($callbacks as $callback) {
                $callback();
            }
        }
    }

    public static function flush(): void
    {
        if (self::$registered) {
            spl_autoload_unregister([self::class, 'load']);
        }

        self::$hooks = [];
        self::$registered = false;
    }
}
