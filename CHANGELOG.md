# Changelog

Notable changes to Nitro. The framework (`nitro/framework`) and the skeleton (`nitro/nitro`) are
released together under the same version number.

## Unreleased

- `eloquent:cache` works on checkouts with CRLF line endings (a Windows clone with
  `core.autocrlf`): the compiler compares its copies of Laravel's methods line-ending-agnostic.
- The compiled Eloquent Model is used only on the PHP version its plans were made with, since the
  boot order follows the method order reflection gives, which can differ between PHP versions.

## v0.41.0

- Compiled Eloquent: `php artisan eloquent:cache`, run by `optimize` and `nitro:optimize`,
  compiles Laravel's Model so that each model's boot methods and initializers, class attributes,
  `#[ObservedBy]` observers and `#[ScopedBy]` scopes are read from a plan made at build time
  rather than found with reflection on every request, and its table declaration is looked up
  once per class rather than once per instance. Only methods identical to the ones Nitro was
  written against are replaced; a model that overrides one of them keeps its own. The compiled
  Model is used only while the installed Laravel Model is the one it was made from and its files
  are still in place. `optimize:clear` (or `eloquent:clear`) removes it.
- Compiled URLs: `route:cache` writes each named route's URL template, and `route()` fills it in
  for routes without a domain, optional parameters, binding fields or a scheme of their own when
  called with exactly their parameters. Every other call uses Laravel's URL generator.
- `config/nitro.php` turns each compilation on or off (`compile.eloquent`, `compile.urls`) and
  lists where models are found (`eloquent.paths`).
- A route cache written by stock Laravel is loaded the way Laravel loads it.

## v0.40.0

- Nitro is built on `laravel/framework`. Earlier releases were a standalone engine.
