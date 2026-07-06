<?php

namespace Nitro\Container;

use Nitro\Container\Contracts\ProfilerInterface;

/**
 * Container Profiler
 *
 * Tracks every container resolution with timing, dependency chains, and build
 * order. It is a dev-time tool that implements the container's ProfilerInterface;
 * attach an instance via Container::setProfiler() to record. The container itself
 * knows nothing about this class — only the interface.
 */
class ContainerProfiler implements ProfilerInterface
{
    private static ?self $instance = null;

    /** @var array<int, array> Ordered log of every resolution */
    private array $resolutions = [];

    /** @var float Application start time */
    private float $startTime;

    /** @var int Resolution counter */
    private int $counter = 0;

    /** @var int Current nesting depth (for dependency chains) */
    private int $depth = 0;

    /** @var array Stack of currently resolving services */
    private array $resolveStack = [];

    private function __construct()
    {
        $this->startTime = microtime(true);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Record the start of a resolution
     */
    public function startResolving(string $abstract, string $method = 'get'): int
    {
        $id = $this->counter++;
        $this->depth++;
        $this->resolveStack[] = $abstract;

        $this->resolutions[$id] = [
            'id'           => $id,
            'abstract'     => $abstract,
            'short_name'   => $this->shortName($abstract),
            'method'       => $method, // get, make, build, singleton, instance
            'depth'        => $this->depth,
            'parent'       => $this->resolveStack[count($this->resolveStack) - 2] ?? null,
            'started_at'   => microtime(true),
            'offset_ms'    => round((microtime(true) - $this->startTime) * 1000, 3),
            'duration_ms'  => null,
            'resolved'     => false,
            'cached'       => false,
            'type'         => null, // class, closure, instance, alias
        ];

        return $id;
    }

    /**
     * Record the end of a resolution
     */
    public function endResolving(int $id, string $type = 'class', bool $cached = false): void
    {
        if (!isset($this->resolutions[$id])) {
            return;
        }

        $this->resolutions[$id]['duration_ms'] = round(
            (microtime(true) - $this->resolutions[$id]['started_at']) * 1000,
            3
        );
        $this->resolutions[$id]['resolved'] = true;
        $this->resolutions[$id]['cached'] = $cached;
        $this->resolutions[$id]['type'] = $type;

        array_pop($this->resolveStack);
        $this->depth--;
    }

    /**
     * Record an instance binding (no resolution needed)
     */
    public function recordInstance(string $abstract): void
    {
        $this->resolutions[$this->counter++] = [
            'id'          => $this->counter - 1,
            'abstract'    => $abstract,
            'short_name'  => $this->shortName($abstract),
            'method'      => 'instance',
            'depth'       => 0,
            'parent'      => null,
            'started_at'  => microtime(true),
            'offset_ms'   => round((microtime(true) - $this->startTime) * 1000, 3),
            'duration_ms' => 0,
            'resolved'    => true,
            'cached'      => true,
            'type'        => 'instance',
        ];
    }

    /**
     * Record a singleton registration
     */
    public function recordRegistration(string $abstract, string $type = 'singleton'): void
    {
        $this->resolutions[$this->counter++] = [
            'id'          => $this->counter - 1,
            'abstract'    => $abstract,
            'short_name'  => $this->shortName($abstract),
            'method'      => 'register',
            'depth'       => 0,
            'parent'      => null,
            'started_at'  => microtime(true),
            'offset_ms'   => round((microtime(true) - $this->startTime) * 1000, 3),
            'duration_ms' => 0,
            'resolved'    => false,
            'cached'      => false,
            'type'        => $type,
        ];
    }

    /**
     * Get all resolutions
     */
    public function getResolutions(): array
    {
        return $this->resolutions;
    }

    /**
     * Get summary statistics
     */
    public function getSummary(): array
    {
        $total = count($this->resolutions);
        $resolved = array_filter($this->resolutions, fn($r) => $r['resolved']);
        $cached = array_filter($this->resolutions, fn($r) => $r['cached']);
        $durations = array_column(array_filter($this->resolutions, fn($r) => $r['duration_ms'] !== null), 'duration_ms');

        return [
            'total_events'      => $total,
            'total_resolved'    => count($resolved),
            'total_cached'      => count($cached),
            'total_built'       => count($resolved) - count($cached),
            'total_duration_ms' => array_sum($durations),
            'max_depth'         => max(array_column($this->resolutions, 'depth') ?: [0]),
            'slowest'           => !empty($durations) ? max($durations) : 0,
        ];
    }

    /**
     * Render the visual debug bar HTML
     */
    public function renderDebugBar(): string
    {
        $resolutions = $this->resolutions;
        $summary = $this->getSummary();
        $totalTime = round((microtime(true) - $this->startTime) * 1000, 2);
        $jsonData = json_encode(array_values($resolutions), JSON_HEX_TAG | JSON_HEX_APOS);
        $jsonSummary = json_encode($summary, JSON_HEX_TAG);

        return <<<HTML
<!-- Nitro Container Profiler -->
<div id="nitro-profiler-toggle" onclick="document.getElementById('nitro-profiler').classList.toggle('ncp-open')" style="
    position:fixed; bottom:12px; right:12px; z-index:99999;
    width:48px; height:48px; border-radius:50%;
    background:linear-gradient(135deg, #0f0f0f 0%, #1a1a2e 100%);
    border:2px solid #00ffc8; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    box-shadow: 0 0 20px rgba(0,255,200,0.3);
    transition: all 0.3s ease;
" onmouseenter="this.style.transform='scale(1.1)'" onmouseleave="this.style.transform='scale(1)'">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#00ffc8" stroke-width="2">
        <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
    </svg>
</div>

<div id="nitro-profiler" style="
    position:fixed; bottom:0; left:0; right:0; z-index:99998;
    max-height:0; overflow:hidden;
    transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    font-family: 'JetBrains Mono', 'Fira Code', 'SF Mono', monospace;
    font-size: 12px;
">
<style>
#nitro-profiler.ncp-open { max-height: 70vh; }
.ncp-wrap {
    background: #0a0a0f;
    border-top: 2px solid #00ffc8;
    color: #c0c0c0;
    overflow-y: auto;
    max-height: 70vh;
}
.ncp-header {
    display: flex;
    align-items: center;
    gap: 24px;
    padding: 12px 20px;
    background: linear-gradient(180deg, #111118 0%, #0a0a0f 100%);
    border-bottom: 1px solid #1a1a2e;
    position: sticky;
    top: 0;
    z-index: 2;
}
.ncp-title {
    font-size: 13px;
    font-weight: 700;
    color: #00ffc8;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}
.ncp-stat {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
}
.ncp-stat-val {
    font-size: 16px;
    font-weight: 700;
    color: #fff;
}
.ncp-stat-label {
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #555;
}
.ncp-tabs {
    display: flex;
    gap: 0;
    padding: 0 20px;
    background: #0d0d14;
    border-bottom: 1px solid #1a1a2e;
}
.ncp-tab {
    padding: 8px 16px;
    cursor: pointer;
    color: #555;
    border-bottom: 2px solid transparent;
    transition: all 0.2s;
}
.ncp-tab:hover { color: #888; }
.ncp-tab.active {
    color: #00ffc8;
    border-bottom-color: #00ffc8;
}
.ncp-content { padding: 0; }

/* Timeline View */
.ncp-timeline {
    padding: 16px 20px;
    position: relative;
}
.ncp-tl-row {
    display: grid;
    grid-template-columns: 28px 200px 1fr 80px 60px;
    align-items: center;
    gap: 8px;
    padding: 4px 0;
    border-bottom: 1px solid #0f0f18;
    transition: background 0.15s;
}
.ncp-tl-row:hover { background: #111120; }
.ncp-tl-idx {
    color: #333;
    text-align: right;
    font-size: 10px;
}
.ncp-tl-name {
    color: #e0e0e0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.ncp-tl-name span { color: #555; }
.ncp-tl-bar-wrap {
    height: 18px;
    position: relative;
    background: #111118;
    border-radius: 2px;
    overflow: hidden;
}
.ncp-tl-bar {
    position: absolute;
    top: 2px;
    bottom: 2px;
    border-radius: 1px;
    min-width: 2px;
    transition: opacity 0.2s;
}
.ncp-tl-dur {
    text-align: right;
    font-variant-numeric: tabular-nums;
}
.ncp-tl-method {
    font-size: 10px;
    padding: 1px 6px;
    border-radius: 3px;
    text-align: center;
    font-weight: 600;
}

/* Method colors */
.ncp-m-instance { background: #1a2e1a; color: #4ade80; }
.ncp-m-register { background: #1a1a2e; color: #818cf8; }
.ncp-m-get { background: #2e2a1a; color: #fbbf24; }
.ncp-m-make { background: #2e1a1a; color: #f87171; }
.ncp-m-build { background: #2e1a2e; color: #e879f9; }

/* Depth colors for bars */
.ncp-d0 { background: #00ffc8; }
.ncp-d1 { background: #00b4d8; }
.ncp-d2 { background: #7c3aed; }
.ncp-d3 { background: #f43f5e; }
.ncp-d4 { background: #f97316; }

/* Tree View */
.ncp-tree { padding: 16px 20px; }
.ncp-tree-node {
    padding: 3px 0;
    display: flex;
    align-items: center;
    gap: 6px;
}
.ncp-tree-indent { color: #222; user-select: none; }
.ncp-tree-icon { width: 14px; text-align: center; }
.ncp-tree-name { color: #e0e0e0; }

/* Table View */
.ncp-table { width: 100%; border-collapse: collapse; }
.ncp-table th {
    text-align: left;
    padding: 8px 12px;
    color: #555;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 1px;
    border-bottom: 1px solid #1a1a2e;
    position: sticky;
    top: 0;
    background: #0d0d14;
}
.ncp-table td {
    padding: 6px 12px;
    border-bottom: 1px solid #0f0f18;
}
.ncp-table tr:hover td { background: #111120; }
.ncp-cached { color: #4ade80; }
.ncp-built { color: #fbbf24; }

/* Close button */
.ncp-close {
    margin-left: auto;
    cursor: pointer;
    color: #555;
    padding: 4px 8px;
    border-radius: 4px;
    transition: all 0.2s;
}
.ncp-close:hover { color: #ff5555; background: #1a0a0a; }

/* Filter */
.ncp-filter {
    background: #111118;
    border: 1px solid #1a1a2e;
    color: #c0c0c0;
    padding: 4px 10px;
    border-radius: 4px;
    font-family: inherit;
    font-size: 11px;
    outline: none;
    width: 160px;
}
.ncp-filter:focus { border-color: #00ffc8; }
</style>

<div class="ncp-wrap">
    <div class="ncp-header">
        <div class="ncp-title">⚡ Nitro Container</div>
        <div class="ncp-stat">
            <div class="ncp-stat-val" id="ncp-total">{$summary['total_events']}</div>
            <div class="ncp-stat-label">Events</div>
        </div>
        <div class="ncp-stat">
            <div class="ncp-stat-val" style="color:#4ade80">{$summary['total_cached']}</div>
            <div class="ncp-stat-label">Cached</div>
        </div>
        <div class="ncp-stat">
            <div class="ncp-stat-val" style="color:#fbbf24">{$summary['total_built']}</div>
            <div class="ncp-stat-label">Built</div>
        </div>
        <div class="ncp-stat">
            <div class="ncp-stat-val" style="color:#00b4d8">{$summary['max_depth']}</div>
            <div class="ncp-stat-label">Max Depth</div>
        </div>
        <div class="ncp-stat">
            <div class="ncp-stat-val">{$totalTime}ms</div>
            <div class="ncp-stat-label">Total</div>
        </div>
        <input class="ncp-filter" placeholder="Filter services..." oninput="ncpFilter(this.value)">
        <div class="ncp-close" onclick="document.getElementById('nitro-profiler').classList.remove('ncp-open')">✕</div>
    </div>

    <div class="ncp-tabs">
        <div class="ncp-tab active" onclick="ncpTab('timeline',this)">Timeline</div>
        <div class="ncp-tab" onclick="ncpTab('tree',this)">Dependency Tree</div>
        <div class="ncp-tab" onclick="ncpTab('table',this)">Table</div>
    </div>

    <div class="ncp-content">
        <div id="ncp-timeline" class="ncp-timeline"></div>
        <div id="ncp-tree" class="ncp-tree" style="display:none"></div>
        <div id="ncp-table-wrap" style="display:none; overflow-x:auto; padding:0"></div>
    </div>
</div>
</div>

<script>
(function(){
    const data = {$jsonData};
    const maxOffset = data.length ? Math.max(...data.map(d => d.offset_ms + (d.duration_ms || 0.1))) : 1;

    function depthClass(d) { return 'ncp-d' + Math.min(d, 4); }
    function methodClass(m) {
        const map = {instance:'instance',register:'register',get:'get',make:'make',build:'build', 'get → build': 'get'};
        return 'ncp-m-' + (map[m] || 'get');
    }

    // Timeline
    function renderTimeline(filter) {
    const el = document.getElementById('ncp-timeline');
    let filtered = filter
        ? data.filter(d => d.abstract.toLowerCase().includes(filter.toLowerCase()))
        : data;

    // Merge get → build pairs
    const merged = [];
    for (let i = 0; i < filtered.length; i++) {
        const curr = filtered[i];
        const next = filtered[i + 1];

        if (
            curr.method === 'get' &&
            next &&
            next.method === 'build' &&
            curr.abstract === next.abstract
        ) {
            merged.push({ ...curr, method: 'get → build' });
            i++; // skip the build row
        } else {
            merged.push(curr);
        }
    }

    el.innerHTML = merged.map((d) => {
        const left = (d.offset_ms / maxOffset * 100).toFixed(2);
        const width = Math.max(((d.duration_ms || 0.1) / maxOffset * 100), 0.3).toFixed(2);
        const dur = d.duration_ms !== null ? d.duration_ms.toFixed(3) + 'ms' : '—';
        const indent = '&nbsp;'.repeat(d.depth * 2);

        return '<div class="ncp-tl-row" title="' + d.abstract + '">' +
            '<div class="ncp-tl-idx">' + d.id + '</div>' +
            '<div class="ncp-tl-name">' + indent + '<span>' + (d.depth > 0 ? '└ ' : '') + '</span>' + d.short_name + '</div>' +
            '<div class="ncp-tl-bar-wrap"><div class="ncp-tl-bar ' + depthClass(d.depth) + '" style="left:' + left + '%;width:' + width + '%"></div></div>' +
            '<div class="ncp-tl-dur">' + dur + '</div>' +
            '<div class="ncp-tl-method ' + methodClass(d.method) + '">' + d.method + '</div>' +
            '</div>';
    }).join('');
}

    // Tree
    function renderTree(filter) {
        const el = document.getElementById('ncp-tree');
        const filtered = filter
            ? data.filter(d => d.abstract.toLowerCase().includes(filter.toLowerCase()))
            : data;

        el.innerHTML = filtered.map(d => {
            const indent = '<span class="ncp-tree-indent">' + '│  '.repeat(Math.max(0, d.depth - 1)) + (d.depth > 0 ? '├─ ' : '') + '</span>';
            const icon = d.cached ? '🟢' : (d.method === 'register' ? '🔵' : '🟡');
            return '<div class="ncp-tree-node">' +
                indent +
                '<span class="ncp-tree-icon">' + icon + '</span>' +
                '<span class="ncp-tree-name">' + d.short_name + '</span>' +
                '<span style="color:#333;margin-left:auto">' + (d.duration_ms !== null ? d.duration_ms.toFixed(3) + 'ms' : '') + '</span>' +
                '</div>';
        }).join('');
    }

    // Table
    function renderTable(filter) {
        const el = document.getElementById('ncp-table-wrap');
        const filtered = filter
            ? data.filter(d => d.abstract.toLowerCase().includes(filter.toLowerCase()))
            : data;

        el.innerHTML = '<table class="ncp-table"><thead><tr>' +
            '<th>#</th><th>Service</th><th>Method</th><th>Type</th><th>Depth</th><th>Offset</th><th>Duration</th><th>Status</th>' +
            '</tr></thead><tbody>' +
            filtered.map(d => {
                const status = d.cached ? '<span class="ncp-cached">cached</span>' : (d.resolved ? '<span class="ncp-built">built</span>' : '<span style="color:#555">pending</span>');
                return '<tr>' +
                    '<td style="color:#333">' + d.id + '</td>' +
                    '<td title="' + d.abstract + '">' + d.short_name + '</td>' +
                    '<td><span class="ncp-tl-method ' + methodClass(d.method) + '">' + d.method + '</span></td>' +
                    '<td style="color:#555">' + (d.type || '—') + '</td>' +
                    '<td style="color:#555">' + d.depth + '</td>' +
                    '<td style="font-variant-numeric:tabular-nums">' + d.offset_ms.toFixed(1) + 'ms</td>' +
                    '<td style="font-variant-numeric:tabular-nums">' + (d.duration_ms !== null ? d.duration_ms.toFixed(3) + 'ms' : '—') + '</td>' +
                    '<td>' + status + '</td>' +
                    '</tr>';
            }).join('') +
            '</tbody></table>';
    }

    // Tab switching
    window.ncpTab = function(tab, el) {
        document.querySelectorAll('.ncp-tab').forEach(t => t.classList.remove('active'));
        el.classList.add('active');
        document.getElementById('ncp-timeline').style.display = tab === 'timeline' ? '' : 'none';
        document.getElementById('ncp-tree').style.display = tab === 'tree' ? '' : 'none';
        document.getElementById('ncp-table-wrap').style.display = tab === 'table' ? '' : 'none';
    };

    // Filter
    let filterVal = '';
    window.ncpFilter = function(val) {
        filterVal = val;
        renderTimeline(val);
        renderTree(val);
        renderTable(val);
    };

    // Initial render
    renderTimeline();
    renderTree();
    renderTable();
})();
</script>
HTML;
    }

    /**
     * Get short class name from FQCN
     */
    private function shortName(string $abstract): string
    {
        if (str_contains($abstract, '\\')) {
            $parts = explode('\\', $abstract);
            return end($parts);
        }
        return $abstract;
    }
}
