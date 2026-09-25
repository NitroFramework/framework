# NitroPHP Framework

**Laravel, with a compiled, lean request path.** Nitro runs on `laravel/framework` itself, so the
whole Laravel API — Eloquent, Blade, validation, queues, Artisan, testing helpers, every package —
is the real thing. Nitro replaces only what happens on each request.

> **Status:** pre-1.0 (`0.x`). From 0.40, Nitro is built on Laravel. Apps that need the previous
> standalone engine can stay on `^0.39` — those releases remain available on Packagist.

## Start a new application

```bash
composer create-project nitro/nitro my-app
```

A Nitro app *is* the stock `laravel/laravel` skeleton. The only differences:

```jsonc
// composer.json
"require": { "nitro/framework": "^0.40" }        // instead of laravel/framework
```

```php
// bootstrap/app.php
use Nitro\Foundation\Application;                   // instead of Illuminate\Foundation\Application
```

Everything else — `php artisan`, routes, `withMiddleware()`, `withExceptions()`, packages — works
exactly as in Laravel.

## What Nitro changes

| Area | Laravel | Nitro |
|---|---|---|
| Core services | Ten framework service providers register on every request | A static factory table; services are built on first use |
| Routing | Symfony matcher + reflection-based dispatch | Compiled table (static map + grouped regex), argument plans, compiled implicit bindings |
| Container | Reflection autowiring | Compiled factories for controllers and middleware (`route:cache`) |
| Eloquent / closure signing | Wired at boot | Wired when the class is first loaded |
| Middleware pipeline | Closure onion built up front | Each layer's closure created only when it runs |

Every replacement is a subclass or a container binding with a fallback to Laravel's own behaviour,
and the test suite checks parity with Laravel (container aliases, route matching on randomly
generated route tables, package providers, facades, config merging).

## Commands

All of Laravel's Artisan commands, plus:

| Command | |
|---|---|
| `php artisan route:cache` | Compiles routes into Nitro's table, and container factories for the classes they build |
| `php artisan optimize [--profile]` | Laravel's optimize; `--profile` times every eager service provider |
| `php artisan view:warm` | Compiles, parse-checks and opcache-primes every Blade view |
| `php artisan nitro:optimize` | Every production cache in one pass, plus a checklist of what still costs time |
| `php artisan nitro:clear` | Reverts `nitro:optimize` |

## Workers

Use [Laravel Octane](https://laravel.com/docs/octane). Nitro runs under Octane's per-request sandbox.

## Requirements

- PHP **8.3+**
- `laravel/framework` **^13.33**

## Testing

```bash
composer install
composer test       # PHPUnit
composer analyse    # PHPStan
```

## Credits & License

See [CREDITS.md](CREDITS.md). Not affiliated with or endorsed by Laravel. Released under the
[MIT license](LICENSE).
