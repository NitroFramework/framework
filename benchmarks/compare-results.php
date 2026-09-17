<?php

declare(strict_types=1);

/**
 * Side-by-side report from two or more k6 result files.
 *
 * Usage:
 *   php benchmarks/compare-results.php benchmarks/results/nitro.json benchmarks/results/laravel.json
 *
 * The first file is the baseline; every other column is reported as a multiple
 * of it. The row that matters is `render marginal` — t1 minus t0 — because it
 * is the only one with the framework floor subtracted out, and therefore the
 * only one that speaks to the template layer specifically.
 */

$files = array_slice($argv, 1);

if (count($files) < 2) {
    fwrite(STDERR, "usage: php benchmarks/compare-results.php <baseline.json> <other.json> [...]\n");
    exit(2);
}

$runs = [];

foreach ($files as $file) {
    if (! is_file($file)) {
        fwrite(STDERR, "missing result file: {$file}\n");
        exit(2);
    }

    $data = json_decode((string) file_get_contents($file), true);

    if (! is_array($data) || ! isset($data['tiers'])) {
        fwrite(STDERR, "not a k6 result file: {$file}\n");
        exit(2);
    }

    $runs[] = $data;
}

$baseline = $runs[0];

// --- header -----------------------------------------------------------------

echo "\n";
printf("  Benchmark comparison  (baseline: %s)\n", $baseline['label']);
printf(
    "  VUS=%s  rows=%s  duration=%s\n\n",
    $baseline['vus'] ?? '?',
    $baseline['rows'] ?? '?',
    $baseline['duration'] ?? '?'
);

warn_on_mismatch($runs);

// --- per-tier table ---------------------------------------------------------

$tiers = array_keys($baseline['tiers']);

$labelWidth = 14;
printf('  %-' . $labelWidth . 's', 'tier (med ms)');
foreach ($runs as $run) {
    printf('%16s', $run['label']);
}
echo "\n  " . str_repeat('-', $labelWidth + 16 * count($runs)) . "\n";

foreach ($tiers as $tier) {
    printf('  %-' . $labelWidth . 's', $tier);

    $base = $baseline['tiers'][$tier]['med'] ?? null;

    foreach ($runs as $i => $run) {
        $value = $run['tiers'][$tier]['med'] ?? null;

        if ($value === null) {
            printf('%16s', '-');
            continue;
        }

        printf('%16s', $i === 0 ? number_format($value, 2) : cell($value, $base));
    }

    echo "\n";
}

// --- the derived row --------------------------------------------------------

echo "\n";
printf('  %-' . $labelWidth . 's', 'render marginal');

$baseMarginal = $baseline['derived']['render_marginal_med'] ?? null;

foreach ($runs as $i => $run) {
    $value = $run['derived']['render_marginal_med'] ?? null;

    if ($value === null) {
        printf('%16s', '-');
        continue;
    }

    printf('%16s', $i === 0 ? number_format($value, 2) : cell($value, $baseMarginal));
}

echo "\n";
echo "  (t1-render minus t0-noop: template cost with the framework floor removed)\n\n";

// --- payload parity ---------------------------------------------------------

echo "  rendered bytes\n";

foreach ($tiers as $tier) {
    $sizes = [];

    foreach ($runs as $run) {
        $sizes[$run['label']] = $run['tiers'][$tier]['bytes'] ?? null;
    }

    $distinct = array_unique(array_filter($sizes, static fn ($s) => $s !== null));

    if (count($distinct) > 1) {
        $parts = [];
        foreach ($sizes as $label => $size) {
            $parts[] = "{$label}=" . ($size ?? '-');
        }
        printf("    %-12s MISMATCH  %s\n", $tier, implode('  ', $parts));
    }
}

echo "    (only mismatches are listed; differing byte counts mean the two sides\n";
echo "     are not rendering the same page, and the latency row is not comparable)\n\n";


// ---------------------------------------------------------------------------

function cell(float $value, ?float $base): string
{
    $formatted = number_format($value, 2);

    if ($base === null || $base <= 0) {
        return $formatted;
    }

    return sprintf('%s (%.1fx)', $formatted, $value / $base);
}

/** Flag run settings that make the columns non-comparable. */
function warn_on_mismatch(array $runs): void
{
    foreach (['vus', 'rows', 'duration'] as $key) {
        $values = array_unique(array_map(
            static fn ($r) => (string) ($r[$key] ?? '?'),
            $runs
        ));

        if (count($values) > 1) {
            printf(
                "  WARNING: runs used different %s (%s) — not comparable\n\n",
                $key,
                implode(', ', $values)
            );
        }
    }
}
