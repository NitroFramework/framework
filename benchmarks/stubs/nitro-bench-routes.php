<?php

/**
 * Benchmark routes — Nitro side.
 *
 * Install:
 *   1. copy benchmarks/stubs/views/*.blade.php  ->  resources/views/
 *   2. require this file from routes/web.php:
 *          require __DIR__ . '/../benchmarks/nitro-bench-routes.php';
 *      (or paste the body in directly)
 *   3. php nitro optimize
 *
 * MIDDLEWARE PARITY NOTE
 * Nitro does not apply the `web` group to routes/web.php automatically — a
 * route gets middleware only when it declares it (see RouteLoader). Laravel
 * wraps every web.php route in `web` by default. So the Laravel stub strips
 * `web` on t0/t1 and keeps it on t3; this file mirrors that exactly, opting
 * in to `web` only on t3. Without that, t0 would be comparing Nitro with no
 * session against Laravel with cookie encryption plus a session file read.
 */

use Nitro\Facades\DB;
use Nitro\Facades\Route;

/**
 * Deterministic fixture rows. Identical generator on every framework under
 * test, so the rendered bytes match and the comparison is of render cost
 * rather than of payload size.
 *
 * @return array<int, array<string, mixed>>
 */
if (! function_exists('bench_rows')) {
    function bench_rows(int $count): array
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'id'     => $i,
                'name'   => "User <b>{$i}</b> & Co",
                'email'  => "user{$i}@example.test",
                'active' => $i % 3 !== 0,
                'score'  => ($i * 7919) % 1000,
            ];
        }

        return $rows;
    }
}

if (! function_exists('bench_rowcount')) {
    function bench_rowcount(): int
    {
        return max(1, min(2000, (int) (request()->query('rows') ?: 200)));
    }
}

// t0 — framework floor. Routing + response. No DB, no view, no middleware.
Route::get('/bench/t0-noop', fn () => ['ok' => true]);

// t1 — view layer in isolation. Fixed in-memory data, no DB, no query cost.
//      This is the tier that answers "how fast is the template layer".
Route::get('/bench/t1-render', fn () => view('bench-page', [
    'rows' => bench_rows(bench_rowcount()),
]));

// t2 — data layer in isolation. One query, JSON out, no view.
Route::get('/bench/t2-db', fn () => [
    'count' => DB::table('bench_rows')->count(),
]);

// t3 — realistic page: full `web` middleware stack + query + render.
Route::get('/bench/t3-page', fn () => view('bench-page', [
    'rows' => DB::table('bench_rows')
        ->orderBy('id')
        ->limit(bench_rowcount())
        ->get(),
]))->middleware('web');
