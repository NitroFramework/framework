<?php

namespace Nitro\Facades;

use Nitro\Database\DB as BaseDB;

/**
 * DB facade — Laravel's `DB::` surface.
 *
 * The query layer's {@see BaseDB} is already a static entry point, so this
 * simply re-exposes it under the consistent `Nitro\Facades` namespace. Because
 * it extends the real class (rather than proxying through __callStatic), the
 * inherited static methods are real — IDEs resolve them with no @method hints.
 *
 * Available (inherited) methods:
 *   DB::table($table)            Start a fluent query on a table.
 *   DB::select($sql, $bindings)  Run a raw SELECT, returning rows.
 *   DB::insert($sql, $bindings)  Run a raw INSERT.
 *   DB::update($sql, $bindings)  Run a raw UPDATE, returning affected rows.
 *   DB::delete($sql, $bindings)  Run a raw DELETE, returning affected rows.
 *   DB::statement($sql, $b)      Run a raw statement (DDL, etc.).
 *   DB::raw($expression)         A raw, un-escaped SQL expression.
 *   DB::transaction($callback)   Run a closure inside a transaction.
 *   DB::beginTransaction()       Begin a transaction.
 *   DB::commit()                 Commit the current transaction.
 *   DB::rollBack()               Roll back the current transaction.
 *
 * Example:
 *   use Nitro\Facades\DB;
 *
 *   $users = DB::table('users')->where('active', 1)->get();
 *   DB::transaction(fn () => DB::table('orders')->insert([...]));
 */
class DB extends BaseDB
{
    
}
