<?php

declare(strict_types=1);

/**
 * Parity preflight for the cross-framework benchmark.
 *
 * A framework comparison is only worth publishing if both sides are configured
 * the same way. This inspects each app directory and reports the settings that
 * dominate request time — debug flags, profiling middleware, opcache, deploy
 * caches — then fails loudly on any asymmetry.
 *
 * Usage:
 *   php benchmarks/doctor.php <app-dir> [<app-dir> ...]
 *   php benchmarks/doctor.php --json <app-dir> ...
 *
 * Exit codes: 0 = comparable, 1 = at least one blocker, 2 = bad invocation.
 */

$args   = array_slice($argv, 1);
$asJson = in_array('--json', $args, true);
$dirs   = array_values(array_filter($args, static fn ($a) => $a !== '--json'));

if ($dirs === []) {
    fwrite(STDERR, "usage: php benchmarks/doctor.php [--json] <app-dir> [<app-dir> ...]\n");
    exit(2);
}

/** Packages that instrument every request. Any of these in `require` is a blocker. */
const PROFILERS = [
    'barryvdh/laravel-debugbar',
    'laravel/telescope',
    'itsgoingd/clockwork',
    'spatie/laravel-ray',
];

/** Packages that add per-request middleware one side may not have. Reported, not fatal. */
const EXTRAS = [
    'livewire/livewire',
    'laravel/sanctum',
    'laravel/octane',
    'silber/page-cache',
    'inertiajs/inertia-laravel',
];

$reports = array_map('inspect', $dirs);

if ($asJson) {
    echo json_encode(
        ['php' => php_facts(), 'apps' => $reports, 'cross' => cross_check($reports)],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ), "\n";
    exit(has_blocker($reports) ? 1 : 0);
}

render($reports);
exit(has_blocker($reports) ? 1 : 0);


// ---------------------------------------------------------------------------
// Inspection
// ---------------------------------------------------------------------------

function inspect(string $dir): array
{
    $dir = rtrim($dir, "/\\");

    $r = [
        'dir'       => $dir,
        'name'      => basename($dir),
        'exists'    => is_dir($dir),
        'framework' => 'unknown',
        'version'   => null,
        'env'       => [],
        'profilers' => [],
        'extras'    => [],
        'caches'    => [],
        'blockers'  => [],
        'warnings'  => [],
    ];

    if (! $r['exists']) {
        $r['blockers'][] = 'directory does not exist';
        return $r;
    }

    $composer = read_json($dir . '/composer.json');
    $require  = $composer['require'] ?? [];

    if (isset($require['laravel/framework'])) {
        $r['framework'] = 'laravel';
        $r['version']   = $require['laravel/framework'];
    } elseif (isset($require['nitro/framework']) || is_file($dir . '/nitro')) {
        $r['framework'] = 'nitro';
        $r['version']   = $require['nitro/framework'] ?? 'path';
    } elseif (isset($require['codeigniter4/framework'])) {
        $r['framework'] = 'codeigniter';
        $r['version']   = $require['codeigniter4/framework'];
    }

    // --- env flags that dominate per-request cost ---------------------------
    $env  = read_env($dir . '/.env');
    $keys = ['APP_ENV', 'APP_DEBUG', 'LOG_LEVEL', 'DB_CONNECTION', 'SESSION_DRIVER',
             'CACHE_DRIVER', 'CACHE_STORE', 'DEBUGBAR_ENABLED'];

    foreach ($keys as $k) {
        $r['env'][$k] = $env[$k] ?? null;
    }

    if (truthy($r['env']['APP_DEBUG'])) {
        $r['blockers'][] = 'APP_DEBUG=true — changes error handling, logging and view '
            . 'freshness checks; benchmark production settings';
    }

    if (strtolower((string) $r['env']['LOG_LEVEL']) === 'debug') {
        $r['warnings'][] = 'LOG_LEVEL=debug writes a log line per request';
    }

    // --- request-instrumenting packages in production require ---------------
    foreach (PROFILERS as $pkg) {
        if (isset($require[$pkg])) {
            $r['profilers'][] = $pkg;
            $r['blockers'][]  = $pkg . ' is in `require` (not `require-dev`) — it '
                . 'instruments every request';
        }
    }

    if (truthy($r['env']['DEBUGBAR_ENABLED'])) {
        $r['blockers'][] = 'DEBUGBAR_ENABLED=true';
    }

    foreach (EXTRAS as $pkg) {
        if (isset($require[$pkg])) {
            $r['extras'][] = $pkg . ' ' . $require[$pkg];
        }
    }

    // --- deploy-time caches -------------------------------------------------
    if ($r['framework'] === 'laravel') {
        $r['caches']['config'] = is_file($dir . '/bootstrap/cache/config.php');
        $r['caches']['routes'] = glob($dir . '/bootstrap/cache/routes-*.php') !== [];

        foreach ($r['caches'] as $name => $built) {
            if (! $built) {
                $r['blockers'][] = "{$name} cache not built — run `php artisan {$name}:cache`";
            }
        }
    }

    if ($r['framework'] === 'nitro') {
        $r['caches']['optimize'] = is_file($dir . '/storage/cache/bootstrap.php')
            || is_file($dir . '/storage/cache/views_manifest.php');

        if (! $r['caches']['optimize']) {
            $r['blockers'][] = 'optimize cache not built — run `php nitro optimize`';
        }
    }

    return $r;
}

/** Asymmetries between the apps, independent of each app's own health. */
function cross_check(array $reports): array
{
    if (count($reports) < 2) {
        return [];
    }

    $issues = [];

    foreach (['APP_DEBUG', 'DB_CONNECTION', 'SESSION_DRIVER'] as $key) {
        $seen = [];

        foreach ($reports as $r) {
            $value        = strtolower((string) ($r['env'][$key] ?? 'unset'));
            $seen[$value] = $r['name'];
        }

        if (count($seen) > 1) {
            $parts = [];
            foreach ($seen as $value => $app) {
                $parts[] = "{$value} ({$app})";
            }
            $issues[] = "{$key} differs across apps: " . implode(', ', $parts);
        }
    }

    return $issues;
}

function has_blocker(array $reports): bool
{
    foreach ($reports as $r) {
        if ($r['blockers'] !== []) {
            return true;
        }
    }

    return cross_check($reports) !== [];
}


// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------

function php_facts(): array
{
    return [
        'version' => PHP_VERSION,
        'opcache' => (bool) ini_get('opcache.enable'),
        'jit'     => ini_get('opcache.jit') ?: 'off',
        'xdebug'  => extension_loaded('xdebug'),
    ];
}

function render(array $reports): void
{
    $php = php_facts();

    echo "\n";
    printf(
        "PHP %s  |  opcache: %s  |  jit: %s  |  xdebug: %s\n\n",
        $php['version'],
        $php['opcache'] ? 'on' : 'OFF',
        $php['jit'],
        $php['xdebug'] ? 'LOADED (invalidates results)' : 'absent'
    );

    foreach ($reports as $r) {
        printf("%s  [%s %s]\n", $r['name'], $r['framework'], (string) $r['version']);

        printf(
            "  env      APP_ENV=%s APP_DEBUG=%s LOG_LEVEL=%s DB=%s SESSION=%s\n",
            $r['env']['APP_ENV'] ?? '-',
            $r['env']['APP_DEBUG'] ?? '-',
            $r['env']['LOG_LEVEL'] ?? '-',
            $r['env']['DB_CONNECTION'] ?? '-',
            $r['env']['SESSION_DRIVER'] ?? '-'
        );

        if ($r['extras'] !== []) {
            printf("  extras   %s\n", implode(', ', $r['extras']));
        }

        if ($r['caches'] !== []) {
            $parts = [];
            foreach ($r['caches'] as $k => $v) {
                $parts[] = $k . '=' . ($v ? 'built' : 'MISSING');
            }
            printf("  caches   %s\n", implode(' ', $parts));
        }

        foreach ($r['warnings'] as $w) {
            printf("  WARN     %s\n", $w);
        }

        foreach ($r['blockers'] as $b) {
            printf("  BLOCKER  %s\n", $b);
        }

        echo "\n";
    }

    $cross = cross_check($reports);

    foreach ($cross as $c) {
        printf("BLOCKER  %s\n", $c);
    }

    if ($cross !== []) {
        echo "\n";
    }

    echo has_blocker($reports)
        ? "RESULT: not comparable — fix the blockers above before benchmarking.\n\n"
        : "RESULT: comparable.\n\n";
}


// ---------------------------------------------------------------------------
// Parsing helpers
// ---------------------------------------------------------------------------

function read_json(string $path): array
{
    if (! is_file($path)) {
        return [];
    }

    return json_decode((string) file_get_contents($path), true) ?: [];
}

function read_env(string $path): array
{
    if (! is_file($path)) {
        return [];
    }

    $out = [];

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $out[trim($key)] = trim(trim($value), "\"'");
    }

    return $out;
}

function truthy(?string $value): bool
{
    return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
}
