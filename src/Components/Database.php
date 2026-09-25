<?php

namespace Nitro\Components;

use Faker\Factory;
use Faker\Generator;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\QueueEntityResolver;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\MigrationCreator;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Pagination\PaginationState;
use Nitro\Foundation\Application;

/**
 * DatabaseServiceProvider + MigrationServiceProvider, wired directly.
 */
final class Database
{
    public static function factory(Application $app): ConnectionFactory
    {
        return new ConnectionFactory($app);
    }

    public static function manager(Application $app): DatabaseManager
    {
        $db = new DatabaseManager($app, $app->make('db.factory'));

        if (class_exists(PaginationState::class)) {
            PaginationState::resolveUsing($app);
        }

        return $db;
    }

    /**
     * DatabaseServiceProvider::boot(). The manager is cheap to build (no connection is opened),
     * so wiring Eloquent at bootstrap costs a couple of object allocations.
     */
    public static function bootEloquent(Application $app): void
    {
        Model::setConnectionResolver($app->make('db'));
        Model::setEventDispatcher($app->make('events'));
    }

    public static function connection(Application $app): mixed
    {
        return $app->make('db')->connection();
    }

    public static function schema(Application $app): mixed
    {
        return $app->make('db')->connection()->getSchemaBuilder();
    }

    public static function transactions(): DatabaseTransactionsManager
    {
        return new DatabaseTransactionsManager;
    }

    public static function concurrencyDetector(): ConcurrencyErrorDetector
    {
        return new ConcurrencyErrorDetector;
    }

    public static function lostConnectionDetector(): LostConnectionDetector
    {
        return new LostConnectionDetector;
    }

    public static function entityResolver(): QueueEntityResolver
    {
        return new QueueEntityResolver;
    }

    /**
     * DatabaseServiceProvider::registerFakerGenerator(): one generator per locale, per process.
     */
    public static function faker(Application $app, array $parameters = []): Generator
    {
        static $fakers = [];

        $locale = $parameters['locale'] ?? $app->make('config')->get('app.faker_locale', 'en_US');

        $fakers[$locale] ??= Factory::create($locale);
        $fakers[$locale]->unique(true);

        return $fakers[$locale];
    }

    public static function migrationRepository(Application $app): DatabaseMigrationRepository
    {
        $migrations = $app->make('config')->get('database.migrations');
        $table = is_array($migrations) ? ($migrations['table'] ?? 'migrations') : ($migrations ?? 'migrations');

        return new DatabaseMigrationRepository($app->make('db'), $table);
    }

    public static function migrator(Application $app): Migrator
    {
        return new Migrator($app->make('migration.repository'), $app->make('db'), $app->make('files'), $app->make('events'));
    }

    public static function migrationCreator(Application $app): MigrationCreator
    {
        return new MigrationCreator($app->make('files'), $app->basePath('stubs'));
    }
}
