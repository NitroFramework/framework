<?php

namespace Nitro\Foundation\Providers;

use Nitro\Database\DB;
use Nitro\Database\Connection;
use Nitro\Database\Migration\MigrationPathRegistry;
use Nitro\Database\Model\ModelState;
use Nitro\Database\Query\Paginator;
use Nitro\Database\Query\QueryRegistry;
use Nitro\Database\RecordModificationState;
use Nitro\Database\Schema\SchemaBuilder;
use Nitro\Events\Contracts\Dispatcher as EventDispatcher;
use Nitro\Foundation\Contracts\ConfigRepository;

/**
 * Register the database connection, schema builder, named queries and migration paths.
 */
class DatabaseServiceProvider extends ServiceProvider
{
    /**
     * Configure the connection and register the database services.
     *
     * The event bus reaches models and the connection here rather than in boot(), so
     * listeners a provider registers in its own boot() hear every event.
     */
    public function register(): void
    {
        $config = $this->container->resolve(ConfigRepository::class);
        $dbConfig = $config->get('database');
        $default = $dbConfig['default'] ?? 'mysql';
        DB::configure($dbConfig['connections'][$default]);

        $this->container->singleton(Connection::class, fn() => DB::connection());
        $this->container->alias(Connection::class, 'db');

        $this->container->instance(RecordModificationState::class, new RecordModificationState());

        ModelState::clearBooted();
        ModelState::setDispatcher($this->container->resolve('events'));

        DB::setDispatcher($this->container->resolve(EventDispatcher::class));

        $this->container->singleton(QueryRegistry::class, function ($container) {
            $registry = new QueryRegistry();
            $registry->loadFrom($container->resolve('paths')->base('app/Queries'));

            return $registry;
        });

        $this->container->singleton(SchemaBuilder::class, fn () => new SchemaBuilder());
        $this->container->alias(SchemaBuilder::class, 'schema');

        $this->container->singleton(MigrationPathRegistry::class, function ($container) {
            $registry = new MigrationPathRegistry();
            $registry->add($container->resolve('paths')->migrations());
            return $registry;
        });
    }

    /**
     * Let the paginator read the current page and URL from the bound request.
     */
    public function boot(): void
    {
        $container = $this->container;

        Paginator::currentPageResolverUsing(static function (string $pageName) use ($container) {
            return $container->has('request')
                ? $container->resolve('request')->query($pageName)
                : null;
        });

        Paginator::currentPathResolverUsing(static function () use ($container) {
            if (! $container->has('request')) {
                return ['path' => '/', 'query' => []];
            }

            $request = $container->resolve('request');

            return [
                'path'  => $request->path(),
                'query' => (array) $request->query(),
            ];
        });
    }
}
