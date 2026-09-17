<?php

namespace Nitro\Facades;

/**
 * Schema facade — proxies the SchemaBuilder.
 *
 *   Schema::create('posts', fn (Blueprint $t) => $t->id());
 *   Schema::dropIfExists('posts');
 *
 * @method static void create(string $table, \Closure $callback)
 * @method static void table(string $table, \Closure $callback)
 * @method static void drop(string $table)
 * @method static void dropIfExists(string $table)
 * @method static void rename(string $from, string $to)
 * @method static bool hasTable(string $table)
 * @method static bool hasColumn(string $table, string $column)
 */
class Schema extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'schema';
    }
}
