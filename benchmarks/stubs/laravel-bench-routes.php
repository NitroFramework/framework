<?php

/**
 * Benchmark routes — Laravel side.
 *
 * Install:
 *   1. copy benchmarks/stubs/views/*.blade.php  ->  resources/views/
 *   2. require this file from routes/web.php:
 *          require __DIR__ . '/../benchmarks/laravel-bench-routes.php';
 *      (or paste the body in directly)
 *   3. php artisan route:cache
 *
 * Every tier is registered WITHOUT the `web` middleware group on purpose for
 * t0/t1: session, CSRF and cookie encryption are real costs but they are not
 * the view layer, and t0 already accounts for middleware. Tier t3 keeps `web`
 * so there is one realistic end-to-end number.
 */

use Illuminate\Support\Facades\Route;

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

// t0 — framework floor. Routing + middleware + response. No DB, no view.
Route::get('/bench/t0-noop', fn () => response()->json(['ok' => true]))
    ->withoutMiddleware(['web']);

// t1 — view layer in isolation. Fixed in-memory data, no DB, no query cost.
//      This is the tier that answers "how fast is the template layer".
Route::get('/bench/t1-render', function () {
    return view('bench-page', ['rows' => bench_rows(bench_rowcount())]);
})->withoutMiddleware(['web']);

// t2 — data layer in isolation. One query, JSON out, no view.
Route::get('/bench/t2-db', function () {
    return response()->json([
        'count' => \Illuminate\Support\Facades\DB::table('bench_rows')->count(),
    ]);
})->withoutMiddleware(['web']);

// t3 — realistic page: full `web` middleware stack + query + render.
Route::get('/bench/t3-page', function () {
    $rows = \Illuminate\Support\Facades\DB::table('bench_rows')
        ->orderBy('id')
        ->limit(bench_rowcount())
        ->get()
        ->map(static fn ($r) => (array) $r)
        ->all();

    return view('bench-page', ['rows' => $rows]);
});
