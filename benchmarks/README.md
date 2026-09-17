# Cross-framework benchmark

Measures Nitro against other PHP frameworks on identical work, and reports the
result **per layer** rather than as a single headline number.

The point is not to produce a number that flatters Nitro. It is to produce a
number that survives someone else re-running it.

## Why the tiers exist

A single "requests per second" figure cannot tell you *why* one framework is
faster, and a gap measured that way is almost always attributed to the wrong
layer. So every run measures four endpoints separately:

| tier | what it does | what it isolates |
|------|--------------|------------------|
| `t0-noop` | route → fixed JSON. No DB, no view. | framework floor: boot (or worker dispatch), routing, response |
| `t1-render` | render a fixed Blade page from in-memory data | the view layer |
| `t2-db` | one query → JSON, no view | the data layer |
| `t3-page` | `web` middleware + query + render | a realistic page |

The figure that actually answers "is the template layer the difference" is
neither `t1` nor `t3`. It is **`t1 - t0`** — the marginal cost of rendering,
with the framework floor subtracted out. The driver computes and prints it as
`render marginal`.

## Parity rules

These are enforced by `doctor.php`, which exits non-zero on violation. They
exist because every one of them has silently invalidated a published PHP
framework benchmark at some point.

- `APP_DEBUG=false` on **both** sides. Debug mode changes error handling,
  logging, and view freshness checking.
- No request-instrumenting package in `require`: Debugbar, Telescope,
  Clockwork, Ray. Debugbar under `APP_DEBUG=true` routinely adds 100 ms+ per
  request, which is larger than the entire quantity being measured.
- Deploy caches built on both sides (`artisan config:cache` + `route:cache`,
  `nitro optimize`).
- Same PHP binary, same opcache and JIT settings, no Xdebug.
- Same database driver, or restrict the run to `t0`/`t1`, which touch no
  database at all.
- Same middleware exposure per tier. This one needs care: **Laravel applies the
  `web` group to every `routes/web.php` route automatically; Nitro does not.**
  The route stubs handle it — Laravel's strip `web` on `t0`/`t1`, Nitro's opt
  in to it on `t3` — so each tier compares like with like.

## Running it

**1. Check parity first.**

```
php benchmarks/doctor.php <path-to-nitro-app> <path-to-laravel-app>
```

Fix everything it reports before going further. A run against a failing doctor
is not a measurement.

**2. Install the workload into both apps.**

Copy `stubs/views/*.blade.php` into each app's view directory, and wire the
matching route stub into its `routes/web.php`:

- `stubs/nitro-bench-routes.php`
- `stubs/laravel-bench-routes.php`

For `t2`/`t3`, both apps need a `bench_rows` table with the same row count.
Skip those tiers with `-e TIERS=t0-noop,t1-render` if you only care about the
framework and view layers — which is the usual case.

Rebuild caches afterwards (`php artisan route:cache`, `php nitro optimize`).

**3. Serve both in production mode.**

Both sides must run the same way. Nitro in worker mode against Laravel under
`artisan serve` is not a comparison — `artisan serve` is a single-threaded
development server and will lose to anything.

```
# Nitro
php nitro thrust:start                 # FrankenPHP worker, :8080

# Laravel
php artisan octane:start --server=frankenphp --port=8000
```

**4. Measure.**

```
k6 run -e BASE_URL=http://localhost:8080 -e LABEL=nitro   benchmarks/k6/compare.js
k6 run -e BASE_URL=http://localhost:8000 -e LABEL=laravel benchmarks/k6/compare.js

php benchmarks/compare-results.php benchmarks/results/nitro.json benchmarks/results/laravel.json
```

Run each target twice and keep the second run. The first pays for opcache fill
and connection setup even after the built-in warmup.

### Knobs

| env | default | meaning |
|-----|---------|---------|
| `BASE_URL` | `http://localhost:8080` | target server |
| `LABEL` | `unlabelled` | names the output file in `results/` |
| `VUS` | `1` | `1` = latency a single user feels; raise for throughput |
| `DURATION` | `20s` | sample window |
| `ROWS` | `200` | table rows on rendered pages |
| `TIERS` | all four | e.g. `t0-noop,t1-render` |
| `WARMUP` | `200` | pre-measurement requests per tier |

`VUS=1` and `VUS=20` answer different questions. Quote which one you ran.

## Reading the output

- **Compare medians, not minimums.** A minimum is one lucky request.
- **Check the `rendered bytes` section.** If the two sides emit different byte
  counts for the same tier, they are not rendering the same page and the
  latency row means nothing. `compare-results.php` prints only mismatches.
- **Attribute the gap to a tier before attributing it to a cause.** If `t0`
  already accounts for the whole difference, the view layer is not involved.

## Cold start is a separate question

Everything above measures warm, steady-state latency. Boot cost — autoloading,
provider registration, config — is amortised to nothing in worker mode and
paid on every request under classic FPM/mod_php. It is a real and often
decisive difference, but it is a different measurement and needs its own run
with warmup disabled and the server in non-worker mode.
