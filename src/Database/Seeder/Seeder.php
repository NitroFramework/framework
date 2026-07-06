<?php

namespace Nitro\Database\Seeder;

use Nitro\Container\Container;

/**
 * Base class for database seeders.
 *
 *   class UsersSeeder extends Seeder {
 *       public function run(): void {
 *           DB::table('users')->insert([
 *               'name' => 'Admin', 'email' => 'admin@example.com', …
 *           ]);
 *       }
 *   }
 *
 * Composition via call():
 *
 *   class DatabaseSeeder extends Seeder {
 *       public function run(): void {
 *           $this->call([
 *               UsersSeeder::class,
 *               PostsSeeder::class,
 *           ]);
 *       }
 *   }
 *
 * call() honors the container so seeders can type-hint dependencies in
 * their constructor. Convention matches Laravel — copying a Laravel
 * seeder over should need no edits beyond namespace changes.
 */
abstract class Seeder
{
    /**
     * Container exposed for subclasses that want to resolve extra services
     * without going through the static facade.
     */
    protected Container $container;

    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? Container::getInstance();
    }

    /**
     * The seed entry point. Override with your inserts / model creates.
     */
    abstract public function run(): void;

    /**
     * Run one or many seeder classes from inside another seeder. Primary
     * use case is DatabaseSeeder, which calls a list of child seeders so
     * `db:seed` only needs the root class name.
     *
     *   $this->call(UsersSeeder::class);
     *   $this->call([UsersSeeder::class, PostsSeeder::class]);
     */
    public function call(string|array $classes): void
    {
        $classes = is_array($classes) ? $classes : [$classes];

        foreach ($classes as $class) {
            if (!class_exists($class) || !is_subclass_of($class, self::class)) {
                throw new \RuntimeException(
                    "Seeder [{$class}] does not exist or does not extend " . self::class
                );
            }
            /** @var Seeder $seeder */
            $seeder = $this->container->make($class);
            $seeder->run();
        }
    }
}
