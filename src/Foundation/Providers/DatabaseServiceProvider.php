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
        $config = $this->container->createOrResolve(ConfigRepository::class);
        $dbConfig = $config->get('database');
        $default = $dbConfig['default'] ?? 'mysql';
        DB::configure($dbConfig['connections'][$default]);

        $this->container->singleton(Connection::class, fn() => DB::connection());
        $this->container->alias('db', Connection::class);

        // A model's booted() hook registers its listeners against whichever
        // dispatcher was current when it ran, but the flag saying it has booted
        // is static and outlives the application that set that dispatcher.
        // Build a second application in the same process — every test does, and
        // so does a worker that rebuilds the app — and the models stay marked
        // booted while their listeners sit on a dispatcher nothing fires any
        // more. Every model-level guard then silently stops applying: an
        // immutable record accepts an update and reports success.
        Model::clearBootedModels();

        // Route model lifecycle events through the app event bus. Set in register()
        // (before any provider boot()) so model-event listeners registered in a
        // provider's boot() land on the dispatcher.
        Model::setEventDispatcher($this->container->createOrResolve('events'));

        // Named-query registry (query('name')). Definitions auto-load from
        // app/Queries/ in boot(); apps needn't have that directory.
        $this->container->singleton(QueryRegistry::class);

        // SchemaBuilder's methods are static and hold no state; the instance
        // exists so 'schema' can be resolved as a service and reached through
        // the Schema facade.
        $this->container->singleton(SchemaBuilder::class, fn () => new SchemaBuilder());
        $this->container->alias('schema', SchemaBuilder::class);

        // Migration path set, seeded with the app's default migrations directory.
        // Module providers add their own dirs via loadMigrationsFrom(); the migrate
        // commands read all() to discover migrations across the app and modules.
        $this->container->singleton(MigrationPathRegistry::class, function ($container) {
            $registry = new MigrationPathRegistry();
            $registry->add($container->createOrResolve('paths')->migrations());
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
                ? $container->createOrResolve('request')->query($pageName)
                : null;
        });

        // Same contract for the URL the paginator builds its links against.
        Paginator::currentPathResolverUsing(static function () use ($container) {
            if (! $container->has('request')) {
                return ['path' => '/', 'query' => []];
            }

            $request = $container->createOrResolve('request');

            return [
                'path'  => $request->path(),
                'query' => (array) $request->query(),
            ];
        });

        // Auto-load named-query definitions from app/Queries/*.php (no-op if absent).
        $container->createOrResolve(QueryRegistry::class)->loadFrom($container->createOrResolve('paths')->base('app/Queries'));
    }
}
