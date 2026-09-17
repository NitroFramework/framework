// Cross-framework benchmark driver.
//
// Runs one target at a time and reports each tier SEPARATELY, so a difference
// in total request time can be attributed to a layer instead of being quoted
// as one headline number.
//
//   t0-noop    routing + middleware + response      -> framework floor
//   t1-render  Blade render, fixed data, no DB      -> the view layer
//   t2-db      one query, JSON out, no view         -> the data layer
//   t3-page    web middleware + query + render      -> realistic page
//
// The interesting quantity is never t3 alone. It is (t1 - t0): the marginal
// cost of rendering the template, with the framework floor subtracted out.
// That is the number that settles whether template caching explains a gap.
//
// Usage:
//   k6 run -e BASE_URL=http://localhost:8080 -e LABEL=nitro     benchmarks/k6/compare.js
//   k6 run -e BASE_URL=http://localhost:8000 -e LABEL=laravel   benchmarks/k6/compare.js
//
//   VUS=1     sequential latency (what a single user feels)
//   VUS=20    throughput under concurrency
//   ROWS=200  table rows per rendered page
//   TIERS=t0-noop,t1-render   restrict to a subset
//
// Results are written to benchmarks/results/<LABEL>.json via handleSummary.

import http from 'k6/http';
import { check } from 'k6';
import { Trend } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'http://localhost:8080';
const LABEL = __ENV.LABEL || 'unlabelled';
const VUS = Number(__ENV.VUS || 1);
const ROWS = Number(__ENV.ROWS || 200);
const DURATION = __ENV.DURATION || '20s';
const WARMUP = Number(__ENV.WARMUP || 200);

const ALL_TIERS = ['t0-noop', 't1-render', 't2-db', 't3-page'];
const TIERS = (__ENV.TIERS ? __ENV.TIERS.split(',') : ALL_TIERS).map((t) => t.trim());

// One Trend per tier. k6's built-in http_req_duration mixes every request
// together; these keep the tiers from contaminating each other.
const tierTrend = {};
TIERS.forEach((t) => {
  tierTrend[t] = new Trend(`tier_${t.replace('-', '_')}`, true);
});

// Rendered-byte size per tier, recorded so a latency difference can be checked
// against payload size. Two frameworks emitting different byte counts for the
// "same" page are not running the same benchmark.
const tierBytes = {};
TIERS.forEach((t) => {
  tierBytes[t] = new Trend(`bytes_${t.replace('-', '_')}`);
});

export const options = {
  scenarios: {
    bench: {
      executor: 'constant-vus',
      vus: VUS,
      duration: DURATION,
      gracefulStop: '5s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
  },
  // Percentiles that matter. Averages hide the compile-on-first-hit spike and
  // any GC or lock contention; p99 is where those show up.
  summaryTrendStats: ['min', 'med', 'avg', 'p(95)', 'p(99)', 'max'],
  discardResponseBodies: false,
};

function url(tier) {
  const needsRows = tier === 't1-render' || tier === 't3-page';
  return `${BASE}/bench/${tier}` + (needsRows ? `?rows=${ROWS}` : '');
}

// Warm every tier before measuring: first hit compiles the template, opens the
// DB connection and fills opcache. Including that in the sample would measure
// cold start, which is a different question and needs its own run.
export function setup() {
  const seen = {};

  TIERS.forEach((tier) => {
    let res;
    for (let i = 0; i < WARMUP; i++) {
      res = http.get(url(tier));
    }
    seen[tier] = {
      status: res.status,
      bytes: res.body ? res.body.length : 0,
    };
  });

  return { warm: seen, label: LABEL, base: BASE, rows: ROWS, vus: VUS };
}

export default function () {
  TIERS.forEach((tier) => {
    const res = http.get(url(tier), { tags: { tier } });

    const ok = check(res, {
      [`${tier} status 200`]: (r) => r.status === 200,
    });

    if (ok) {
      tierTrend[tier].add(res.timings.duration);
      tierBytes[tier].add(res.body ? res.body.length : 0);
    }
  });
}

export function handleSummary(data) {
  const out = {
    label: LABEL,
    base_url: BASE,
    vus: VUS,
    rows: ROWS,
    duration: DURATION,
    generated_at: new Date().toISOString(),
    tiers: {},
  };

  TIERS.forEach((tier) => {
    const key = `tier_${tier.replace('-', '_')}`;
    const byteKey = `bytes_${tier.replace('-', '_')}`;
    const m = data.metrics[key];
    const b = data.metrics[byteKey];

    if (!m) return;

    out.tiers[tier] = {
      count: m.values.count,
      min: round(m.values.min),
      med: round(m.values.med),
      avg: round(m.values.avg),
      p95: round(m.values['p(95)']),
      p99: round(m.values['p(99)']),
      max: round(m.values.max),
      bytes: b ? Math.round(b.values.avg) : null,
    };
  });

  // The derived figure: render cost with the framework floor removed.
  if (out.tiers['t0-noop'] && out.tiers['t1-render']) {
    out.derived = {
      render_marginal_med: round(out.tiers['t1-render'].med - out.tiers['t0-noop'].med),
      render_marginal_p95: round(out.tiers['t1-render'].p95 - out.tiers['t0-noop'].p95),
      note: 't1 minus t0 — the marginal cost of rendering the template itself',
    };
  }

  return {
    stdout: text(out),
    [`benchmarks/results/${LABEL}.json`]: JSON.stringify(out, null, 2),
  };
}

function round(n) {
  return typeof n === 'number' ? Math.round(n * 1000) / 1000 : null;
}

function text(out) {
  const lines = [];
  lines.push('');
  lines.push(`  ${out.label}  —  ${out.base_url}  (VUS=${out.vus}, rows=${out.rows})`);
  lines.push('');
  lines.push('  tier        med      p95      p99      bytes');
  lines.push('  ' + '-'.repeat(46));

  Object.keys(out.tiers).forEach((tier) => {
    const t = out.tiers[tier];
    lines.push(
      '  ' +
        tier.padEnd(11) +
        fmt(t.med) +
        fmt(t.p95) +
        fmt(t.p99) +
        String(t.bytes === null ? '-' : t.bytes).padStart(10)
    );
  });

  if (out.derived) {
    lines.push('');
    lines.push(
      `  render marginal (t1-t0):  med ${out.derived.render_marginal_med} ms   ` +
        `p95 ${out.derived.render_marginal_p95} ms`
    );
  }

  lines.push('');
  return lines.join('\n');
}

function fmt(n) {
  return (n === null ? '-' : n.toFixed(2)).padStart(9);
}
