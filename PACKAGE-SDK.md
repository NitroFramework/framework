# Package SDK — Milestone Sketch

**Status:** proposed. Builds on package auto-discovery (v0.21.0), which already
auto-registers a package's **providers** (`extra.nitro.providers`) and **commands**
(`extra.nitro.commands`) on `composer require`. This milestone adds the
*package-author convenience layer* — the helpers that make authoring a Nitro
package feel like authoring a Laravel package. Nothing here is required to ship a
working package today; it's ergonomics + the last discovery gaps.

## Goal

A package author writes a service provider like this and it "just works":

```php
class BlogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // package defaults merge UNDER the app's config (app wins)
        $this->mergeConfigFrom(__DIR__.'/../config/blog.php', 'blog');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'blog');      // blog::post
        $this->loadRoutesFrom(__DIR__.'/../routes/blog.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'blog');

        // opt-in copies the user can customize
        $this->publishes([
            __DIR__.'/../config/blog.php' => config_path('blog.php'),
        ], 'blog-config');
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/blog'),
        ], 'blog-views');
    }
}
```

## Deliverables

### 1. `ServiceProvider` base helpers
Add to `Nitro\Foundation\Providers\ServiceProvider` (each is thin — it forwards
to an existing Nitro subsystem; the point is one ergonomic call):

| Helper | Wraps | Notes |
|---|---|---|
| `mergeConfigFrom($path, $key)` | config repository | recursive merge, **app config wins** |
| `loadViewsFrom($path, $ns)` | `ViewEngine::addNamespace` | exactly what Fusion does by hand today |
| `loadRoutesFrom($path)` | Router `require` | skip if the route cache is warm (prod) |
| `loadMigrationsFrom($path)` | migrator paths | register, don't run |
| `loadTranslationsFrom($path, $ns)` | translator | if/when i18n lands |
| `commands([...])` | `CommandManager::registerCommandClass` | alternative to `extra.nitro.commands` |
| `publishes($map, $group)` | publish registry | records source→dest for `vendor:publish` |

Fusion is the proof case: today its provider calls `addNamespace('fusion', …)`
directly and leans on `config()` fallbacks. With `loadViewsFrom` + `mergeConfigFrom`
it becomes a two-liner, and the config becomes *publishable*.

### 2. `nitro vendor:publish` command
Mirrors `artisan vendor:publish`:
```
nitro vendor:publish                        # interactive: pick a package/group
nitro vendor:publish --tag=blog-config      # publish one group
nitro vendor:publish --provider=Blog\\...   # everything a provider registered
nitro vendor:publish --force                # overwrite existing
```
Reads the `publishes()` registry populated during provider boot, copies files
(dirs recursively), never clobbers without `--force`. This is the single biggest
missing piece — without it a package can't ship customizable config/views/stubs.

### 3. Wire `extra.nitro.aliases` (currently inert)
`PackageManifest::aliases()` already reads it; nothing consumes it. Add an alias
registrar (boot step) that `class_alias()`es each `alias => FQCN`, so a package
can expose a short facade/class name:
```json
"extra": { "nitro": { "aliases": { "Blog": "Blog\\Facades\\Blog" } } }
```
(Depends on whether Nitro grows a Facade layer — if not, this stays a plain
class-alias convenience.)

### 4. Path helpers
`config_path()`, `resource_path()`, `database_path()`, `lang_path()` — the
`*_path()` helpers `publishes()` targets read naturally. Some exist; audit + fill
gaps so provider code reads like Laravel's.

## Sequencing
1. `mergeConfigFrom` + `loadViewsFrom` (unblocks clean package config/views; refactor Fusion onto them as the proof).
2. `publishes()` registry + `vendor:publish` command.
3. `loadRoutesFrom` / `loadMigrationsFrom` (+ prod route-cache awareness).
4. `aliases` registrar + `*_path()` audit.

## Non-goals (for this milestone)
- A Facade system (separate, larger decision).
- Package auto-*testing* harness.
- Publishing to Packagist automation.

## Definition of done
A third-party package can ship providers, commands, **config, views, routes,
migrations, and publishable assets**, and install with a single `composer require`
+ optional `nitro vendor:publish` — i.e. full Laravel-package parity for the
author. Fusion refactored to use `loadViewsFrom`/`mergeConfigFrom`/`publishes`
as the reference consumer.
