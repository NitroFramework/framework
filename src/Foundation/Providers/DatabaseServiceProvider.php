<?php

namespace Nitro\Foundation\Providers;

use Nitro\Database\DB;
use Nitro\Database\Connection;
use Nitro\Database\Migration\MigrationPathRegistry;
use Nitro\Database\Model\Model;
use Nitro\Database\Query\Paginator;
use Nitro\Database\Query\QueryRegistry;
use Nitro\Database\Schema\SchemaBuilder;
use Nitro\Foundation\Contracts\ConfigRepository;

/**
 * Registers the database connection, schema builder and migration path registry.
 */
class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->container->get(ConfigRepository::class);
        $dbConfig = $config->get('database');
        $default = $dbConfig['default'] ?? 'mysql';
        DB::configure($dbConfig['connections'][$default]);

        $this->container->singleton(Connection::class, fn() => DB::connection());
        $this->container->alias('db', Connection::class);

        // Route model lifecycle events through the app event bus. Set in register()
        // (before any provider boot()) so model-event listeners registered in a
        // provider's boot() land on the dispatcher.
        Model::setEventDispatcher($this->container->get('events'));

        // Named-query registry (query('name')). Definitions auto-load from
        // app/Queries/ in boot(); apps needn't have that directory.
        $this->container->singleton(QueryRegistry::class);

        // $this->container->singleton(SchemaBuilder::class, fn() => new SchemaBuilder());
        $this->container->alias('schema', SchemaBuilder::class);

        // Migration path set, seeded with the app's default migrations directory.
        // Module providers add their own dirs via loadMigrationsFrom(); the migrate
        // commands read all() to discover migrations across the app and modules.
        $this->container->singleton(MigrationPathRegistry::class, function ($container) {
            $registry = new MigrationPathRegistry();
            $registry->add($container->get('paths')->migrations());
            return $registry;
        });
    }

    /**
     * Teach the Paginator how to read the current page from the request, so the
     * query layer resolves ?page= through the bound Request instead of $_GET.
     * Resolved lazily per call → safe in the persistent worker (each request
     * rebinds 'request'); returns null on console where no request is bound.
     */
    public function boot(): void
    {
        $container = $this->container;

        Paginator::currentPageResolverUsing(static function (string $pageName) use ($container) {
            return $container->has('request')
                ? $container->make('request')->query($pageName)
                : null;
        });

        // Auto-load named-query definitions from app/Queries/*.php (no-op if absent).
        $container->get(QueryRegistry::class)->loadFrom($container->get('paths')->base('app/Queries'));
    }
}
