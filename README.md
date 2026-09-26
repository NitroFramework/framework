# Nitro

Nitro is a PHP framework for building web applications. It is built on
[`laravel/framework`](https://github.com/laravel/framework): Eloquent, Blade, validation, queues,
mail, Artisan and the testing tools are the ones you already know, Laravel's documentation applies
as written, and Laravel packages install and work as they do in any Laravel application.

What Nitro adds is its own application core and a production build step. The work Laravel repeats
on every request to find out things that cannot change between deployments (which routes exist,
how controllers are built, what each model declares, how a named route's URL is shaped) is done
once, by `php artisan optimize`, and read back from compiled files. Services are built when a
request first needs them rather than registered up front.

Each of these steps has a fallback to Laravel's own code, can be switched off on its own, and is
tested against the Laravel behaviour it stands in for.

## Laravel's layers and Nitro's

Nitro uses Laravel's components and layers as they are:

- Eloquent and the query builder, migrations and schema
- Blade, views and components
- Validation, authentication and authorization
- Sessions, cookies, encryption and hashing
- Cache, queues, events, mail and notifications
- Filesystem, logging, HTTP client and the scheduler
- Artisan and the testing tools
- The container, facades and service providers your application and its packages register

Nitro provides its own:

- **Application and kernels:** `Nitro\Foundation\Application`, the HTTP and console kernels and
  the bootstrap sequence
- **Components:** the core services, built on first use instead of registered by service providers
- **Routing:** the router, the compiled route table, the dispatcher and the middleware pipeline
- **Container factories:** compiled construction for the controllers and middleware routes build
- **Compiled Eloquent:** the model plans and the compiled Model
- **URL generation:** `route()` from compiled URL templates
- **Build commands:** `route:cache`, `eloquent:cache`, `optimize`, `view:warm`, `nitro:optimize`

## Getting started

Create an application from the skeleton:

```bash
composer create-project nitro/nitro my-app
```

A Nitro application is laid out like any Laravel application. It requires `nitro/framework`, and
`bootstrap/app.php` configures `Nitro\Foundation\Application`:

```php
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Nitro\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
```

Routes, controllers, middleware, models, views and Artisan commands are written as in Laravel.

**Requirements:** PHP 8.3 or later.

## How it works

**Services on demand.** The framework's core services (routing, sessions, cookies, encryption,
views, the database, validation and the rest) come from a table of factories in `Nitro\Components`
instead of service providers. A service is built the first time something asks for it, so a
request only pays for what it uses. Your application's providers and package providers are
registered and booted as usual.

**Compiled routing.** `route:cache` writes Nitro's own route table: exact paths in a lookup map,
the rest in grouped regular expressions in registration order. Along with it come each route's
middleware stack, controller and argument plan, implicit bindings, and the URL template `route()`
fills in. The container factories for the controllers and middleware those routes use are written
next to it, so building them needs no reflection.

**Compiled Eloquent.** `eloquent:cache` compiles Laravel's `Model` from the installed source, with
a handful of methods reading from a plan made for each of your models: which boot methods and
initializers it has, its class attributes (`#[Table]`, `#[Fillable]`, `#[Hidden]`, ...), its
`#[ObservedBy]` observers and `#[ScopedBy]` scopes. A method is only replaced when it is exactly
the one Nitro was written against. The compiled Model is only loaded while the installed Laravel
version is the one it was made from; otherwise Laravel's Model is used until the next build.

**Lazy wiring.** Eloquent's connection resolver, the closure signing key and queued job context
are set up when their classes are first loaded, not at boot.

## Production

Build every cache as part of your deployment:

```bash
php artisan optimize
```

This runs Laravel's config, event, route and view caches, with Nitro's route table, container
factories and compiled Eloquent. `php artisan optimize:clear` removes them all.

`php artisan nitro:optimize` does the same in one pass and adds `view:warm` (compiles and
parse-checks every Blade view, so a broken template fails the deploy instead of a request), an
optimized Composer autoloader, and a checklist of production settings. `php artisan nitro:clear`
reverts it.

Nitro also runs under [Laravel Octane](https://laravel.com/docs/octane).

## Configuration

Every compilation is on by default. To change that, copy [`config/nitro.php`](config/nitro.php)
into your application's `config/` directory:

| Key | Default | |
|---|---|---|
| `compile.eloquent` (`NITRO_COMPILE_ELOQUENT`) | `true` | The compiled Eloquent Model |
| `compile.urls` (`NITRO_COMPILE_URLS`) | `true` | Compiled URL templates for `route()` |
| `eloquent.paths` | `[app_path()]` | Where `eloquent:cache` looks for models |

Turning a compilation off runs that part on Laravel's code, unchanged.

## Commands

Alongside Laravel's Artisan commands:

| Command | |
|---|---|
| `route:cache` | Nitro's route table and the container factories for what the routes build |
| `eloquent:cache` / `eloquent:clear` | The compiled Eloquent Model and the model plans |
| `optimize [--profile]` | Every cache above plus Laravel's; `--profile` times each eager service provider |
| `view:warm` | Compile, parse-check and opcache-prime every Blade view |
| `nitro:optimize` | Every production cache in one pass, with a production checklist |
| `nitro:clear` | Undo `nitro:optimize` |

## Testing

```bash
composer install
composer test       # PHPUnit
composer analyse    # PHPStan
```

The suite runs Nitro and a stock Laravel application side by side on the same scenarios (routing,
container bindings, package providers, facades, configuration, URL generation, Eloquent models)
and compares what each does. `vendor/bin/phpunit --bootstrap tests/bootstrap-compiled.php` runs the
whole suite on the compiled Eloquent Model.

## Contributing

Contributions are welcome: bug reports, fixes, tests and improvements.

1. **Open an issue first** for anything beyond a small fix, so the approach can be agreed before
   you spend time on it. Bugs are easiest to fix with the smallest route, model or command that
   shows them.
2. **Fork the repository and branch from `main`.**
3. **Add a test with the change.** Where Nitro stands in for something Laravel does, the test
   should show that Nitro does exactly what Laravel does (`tests/Compat` has the side-by-side
   helpers). New compiled behaviour also needs a fallback to Laravel's code, and a switch in
   `config/nitro.php` when it can be turned off.
4. **Run the checks:**

   ```bash
   composer test
   composer analyse
   vendor/bin/phpunit --bootstrap tests/bootstrap-compiled.php   # when touching Eloquent
   ```

5. **Follow the code style:** comments as docblocks (`/** ... */`), not `//`; imported class names
   rather than fully qualified ones in code; names and comments that say what the code does.
6. **Open a pull request** describing what changes and why.

Please report security issues privately through
[GitHub's security advisories](https://github.com/NitroFramework/framework/security/advisories/new)
rather than in a public issue.

## Versioning

Nitro is pre-1.0. The framework and the skeleton are released together under the same version
number, and `0.MINOR` is the compatibility boundary. Releases before 0.40 were a standalone engine,
not built on `laravel/framework`, and remain available for applications that use them. See
[CHANGELOG.md](CHANGELOG.md).

## Credits and license

Nitro is created by [Zeeshan Ali](https://github.com/ZeeshanX4). It builds on the components of
[Laravel](https://laravel.com), created by Taylor Otwell and its contributors, released under the
MIT License. Nitro is not affiliated with or endorsed by Laravel.

Released under the [MIT license](LICENSE).
