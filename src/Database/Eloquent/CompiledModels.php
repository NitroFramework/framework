<?php

namespace Nitro\Database\Eloquent;

use Composer\Autoload\ClassLoader;
use Exception;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Nitro\Foundation\ClassLoadHooks;
use ReflectionClass;
use ReflectionMethod;

/**
 * Runtime side of the compiled Eloquent Model (see ModelCompiler).
 *
 * The compiled Model is loaded in place of Laravel's only while the Laravel Model it was made
 * from is still the installed one and PHP is the version the plans were made with; otherwise
 * (after `composer update`, or a PHP upgrade) Laravel's own Model loads until the next
 * `php artisan optimize`.
 */
final class CompiledModels
{
    /** @var array<class-string, array{0: list<string>, 1: array<int, string>, 2: array<string, array{0: string, 1: array}>|null, 3: list<mixed>|null, 4: list<mixed>|null}> */
    private static array $plans = [];

    /** @var array<class-string, array{0: bool, 1: bool}> */
    private static array $tables = [];

    /**
     * Load the compiled Model instead of Laravel's when Model is first used, if it is current.
     */
    public static function register(string $model, string $manifest): void
    {
        if (class_exists(Model::class, false) || ! is_file($model) || ! is_file($manifest)) {
            return;
        }

        ClassLoadHooks::replace(Model::class, $model, static function () use ($model, $manifest): bool {
            /** The caches may have been cleared since boot (optimize:clear in this process). */
            if (! is_file($model) || ! is_file($manifest)) {
                return false;
            }

            $compiled = require $manifest;

            if (! is_array($compiled) || ($compiled['php'] ?? null) !== PHP_VERSION
                || $compiled['laravel'] !== self::stamp(self::laravelModelPath())) {
                return false;
            }

            self::$plans = $compiled['plans'];

            return true;
        });
    }

    /**
     * The file Composer loads Laravel's Model from (not a compiled copy already loaded).
     */
    public static function laravelModelPath(): ?string
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            if (is_string($file = $loader->findFile(Model::class))) {
                return realpath($file) ?: $file;
            }
        }

        return null;
    }

    /**
     * Identifies the Laravel Model a compiled copy was made from.
     *
     * @return array{0: int, 1: int}|null [size, mtime]
     */
    public static function stamp(?string $path): ?array
    {
        if ($path === null || ($size = @filesize($path)) === false || ($mtime = @filemtime($path)) === false) {
            return null;
        }

        return [$size, $mtime];
    }

    /**
     * Model::bootTraits() from the compiled plan: the same static methods invoked in the same
     * order through reflection (so private methods and `static::` resolve as in Laravel), and
     * the same initializer list. False when the class has no plan.
     *
     * @param  array<class-string, array<int, string>>  $initializers  Model::$traitInitializers
     */
    public static function bootTraits(string $class, array &$initializers): bool
    {
        if (! isset(self::$plans[$class])) {
            return false;
        }

        [$boot, $initialize] = self::$plans[$class];

        $initializers[$class] = [];

        foreach ($boot as $method) {
            (new ReflectionMethod($class, $method))->invoke(null);
        }

        $initializers[$class] = $initialize;

        return true;
    }

    /**
     * Model::resolveClassAttribute() from the compiled plan: a new instance of the attribute it
     * would find (built from the same arguments, as ReflectionAttribute::newInstance() does), null
     * when it would find none or its constructor throws, false when the class has no compiled
     * attributes.
     */
    public static function classAttribute(string $class, string $attributeClass): object|false|null
    {
        if (! isset(self::$plans[$class][2])) {
            return false;
        }

        if (! isset(self::$plans[$class][2][$key = strtolower($attributeClass)])) {
            return null;
        }

        [$name, $arguments] = self::$plans[$class][2][$key];

        try {
            return new $name(...$arguments);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * HasEvents::resolveObserveAttributes() from the compiled plan, or null to resolve it there.
     */
    public static function observers(string $class): ?array
    {
        return self::$plans[$class][3] ?? null;
    }

    /**
     * HasGlobalScopes::resolveGlobalScopeAttributes() from the compiled plan, or null to resolve
     * it there.
     */
    public static function scopes(string $class): ?array
    {
        return self::$plans[$class][4] ?? null;
    }

    /**
     * What Model::initializeModelAttributes() reflects on each instance, once per class:
     * whether the class itself declares $table, and whether it carries #[Table].
     *
     * @return array{0: bool, 1: bool}
     */
    public static function tableDeclaration(string $class): array
    {
        if (isset(self::$tables[$class])) {
            return self::$tables[$class];
        }

        $reflection = new ReflectionClass($class);

        $declaresTable = $reflection->hasProperty('table')
            && $reflection->getProperty('table')->getDeclaringClass()->getName() === $class;

        return self::$tables[$class] = [$declaresTable, $reflection->getAttributes(Table::class) !== []];
    }

    /**
     * @param  array<class-string, array{0: list<string>, 1: array<int, string>}>  $plans
     */
    public static function usePlans(array $plans): void
    {
        self::$plans = $plans;
    }
}
