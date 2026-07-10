<?php

namespace Nitro\Database\Factory;

/**
 * Mixin for Model subclasses that exposes ::factory(). Matches the
 * Laravel API exactly so test/seeder code copied between projects works:
 *
 *   class User extends Model {
 *       use HasFactory;
 *   }
 *
 *   User::factory()->create();
 *   User::factory()->count(10)->create();
 *
 * The factory class is resolved by convention:
 *   App\Models\User  →  Database\Factories\UserFactory
 *
 * Override factoryClass() if you need a different convention or want
 * to inject a specific factory subclass for tests.
 */
trait HasFactory
{
    public static function factory(?int $count = null): Factory
    {
        $factoryClass = static::factoryClass();

        if (!class_exists($factoryClass)) {
            throw new \RuntimeException(
                "Factory class not found: {$factoryClass}. "
                . "Run `php nitro make:factory " . self::shortName() . "Factory` to scaffold one."
            );
        }
        if (!is_subclass_of($factoryClass, Factory::class)) {
            throw new \RuntimeException(
                "{$factoryClass} must extend " . Factory::class
            );
        }

        /** @var Factory $factory */
        $factory = new $factoryClass();
        return $count !== null ? $factory->count($count) : $factory;
    }

    /**
     * Convention: short model name + 'Factory' under Database\Factories\.
     * Override if your project organizes factories differently.
     */
    protected static function factoryClass(): string
    {
        return 'Database\\Factories\\' . self::shortName() . 'Factory';
    }

    private static function shortName(): string
    {
        return (new \ReflectionClass(static::class))->getShortName();
    }
}
