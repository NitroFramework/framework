# NitroPHP Framework

The engine behind NitroPHP — a lean, fast, Laravel-shaped PHP framework.

This is the framework library (the `Nitro\` namespace). To start a new
application, use the [`nitro/nitro`](https://github.com/ZeeshanX4) skeleton
rather than installing this package directly.

> **Status:** pre-1.0 (`0.x`). The API is stabilising but may still change
> between minor versions.

## Install

```bash
composer require nitro/framework
```

## What's inside

Routing, an Eloquent-style ORM and query builder, a Blade-compatible view
engine, validation, queues, caching, auth, events, a console kernel, a
persistent FrankenPHP worker runtime (Thrust), and a reactive component layer —
on a deliberately small, high-performance core.

## Requirements

- PHP **8.2+** (developed against **8.5**)
- Composer

## Testing

```bash
composer install
composer test
```

## Credits & License

Mirrors the naming/ergonomics of, and is inspired by, several independent
projects — see [CREDITS.md](CREDITS.md). Not affiliated with or endorsed by
them. Released under the [MIT License](LICENSE).
