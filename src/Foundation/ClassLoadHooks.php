<?php

namespace Nitro\Foundation;

use Composer\Autoload\ClassLoader;

/**
 * Run a callback right after a class is first autoloaded. Lets bootstrap wiring that Laravel
 * performs eagerly (Eloquent's connection resolver, the closure signing key) happen only when
 * the class is actually used, so requests that never touch a model load none of Eloquent.
 *
 * A class can also be loaded from a compiled file instead of Composer's (the compiled Eloquent
 * Model), when a check made at that moment passes; its hooks run either way.
 *
 * One prepended autoloader serves all hooks and unregisters itself when none remain.
 */
final class ClassLoadHooks
{
    /** @var array<class-string, list<callable>> */
    private static array $hooks = [];

    /** @var array<class-string, array{0: string, 1: callable(): bool}> */
    private static array $replacements = [];

    private static bool $registered = false;

    public static function after(string $class, callable $callback): void
    {
        if (class_exists($class, false)) {
            $callback();

            return;
        }

        self::$hooks[$class][] = $callback;

        self::register();
    }

    /**
     * Load $class from $file instead of through Composer, if $when() returns true when the class
     * is first needed.
     *
     * @param  callable(): bool  $when
     */
    public static function replace(string $class, string $file, callable $when): void
    {
        if (class_exists($class, false)) {
            return;
        }

        self::$replacements[$class] = [$file, $when];

        self::register();
    }

    public static function load(string $class): void
    {
        if (! isset(self::$hooks[$class]) && ! isset(self::$replacements[$class])) {
            return;
        }

        $callbacks = self::$hooks[$class] ?? [];
        $replacement = self::$replacements[$class] ?? null;
        unset(self::$hooks[$class], self::$replacements[$class]);

        if ($replacement !== null && ($replacement[1])()) {
            require $replacement[0];
        } else {
            foreach (ClassLoader::getRegisteredLoaders() as $loader) {
                if ($loader->loadClass($class)) {
                    break;
                }
            }
        }

        if (self::$hooks === [] && self::$replacements === []) {
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
        self::$replacements = [];
        self::$registered = false;
    }

    private static function register(): void
    {
        if (! self::$registered) {
            spl_autoload_register([self::class, 'load'], true, true);
            self::$registered = true;
        }
    }
}
