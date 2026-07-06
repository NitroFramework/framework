<?php

namespace Nitro\PerformanceBar\Panels;

use Nitro\Container\ContainerProfiler;
use Nitro\PerformanceBar\Contracts\PanelInterface;

/**
 * Container Panel — wraps ContainerProfiler data into a PerformanceBar panel.
 * ContainerProfiler itself remains unchanged; this is just a rendering adapter.
 */
class ContainerPanel implements PanelInterface
{
    private array $resolutions = [];
    private array $summary = [];

    public function getId(): string { return 'container'; }
    public function getName(): string { return '🔷 Container'; }

    public function collect(): void
    {
        $profiler = ContainerProfiler::getInstance();
        $this->resolutions = $profiler->getResolutions();
        $this->summary = $profiler->getSummary();
    }

    public function renderBadge(): string
    {
        return isset($this->summary['total_events'])
            ? (string) $this->summary['total_events']
            : '';
    }

    public function renderContent(): string
    {
        if (empty($this->resolutions)) {
            return '<p style="color:#555;padding:20px">No container resolutions recorded. Make sure profiling is enabled.</p>';
        }

        $s = $this->summary;
        $jsonData = json_encode(array_values($this->resolutions), JSON_HEX_TAG | JSON_HEX_APOS);
        $maxOffset = 1;
        foreach ($this->resolutions as $r) {
            $end = $r['offset_ms'] + ($r['duration_ms'] ?? 0.1);
            if ($end > $maxOffset) $maxOffset = $end;
        }

        return <<<HTML
<div class="ndb-perf-grid">
    <div class="ndb-metric-card">
        <div class="ndb-metric-val">{$s['total_events']}</div>
        <div class="ndb-metric-lbl">Total Events</div>
    </div>
    <div class="ndb-metric-card">
        <div class="ndb-metric-val" >{$s['total_cached']}</div>
        <div class="ndb-metric-lbl">Cached</div>
    </div>
    <div class="ndb-metric-card">
        <div class="ndb-metric-val" >{$s['total_built']}</div>
        <div class="ndb-metric-lbl">Built</div>
    </div>
    <div class="ndb-metric-card">
        <div class="ndb-metric-val" >{$s['max_depth']}</div>
        <div class="ndb-metric-lbl">Max Depth</div>
    </div>
    <div class="ndb-metric-card">
        <div class="ndb-metric-val">{$s['total_duration_ms']}ms</div>
        <div class="ndb-metric-lbl">Total DI Time</div>
    </div>
    <div class="ndb-metric-card">
        <div class="ndb-metric-val">{$s['slowest']}ms</div>
        <div class="ndb-metric-lbl">Slowest Build</div>
    </div>
</div>

<div class="ndb-container-tabs">
    <button class="ndb-sub-tab active" onclick="ncpSubTab('timeline',this)">Timeline</button>
    <button class="ndb-sub-tab" onclick="ncpSubTab('tree',this)">Dependency Tree</button>
    <button class="ndb-sub-tab" onclick="ncpSubTab('table',this)">Table</button>
    <input class="ndb-filter" placeholder="Filter services..." oninput="ncpFilter(this.value)">
</div>

<div id="ncp-timeline" class="ncp-timeline"></div>
<div id="ncp-tree" class="ncp-tree" style="display:none"></div>
<div id="ncp-table-wrap" style="display:none"></div>

<script>
(function(){
    const data = {$jsonData};
    const maxOffset = {$maxOffset};

    function depthClass(d) { return 'ncp-d' + Math.min(d, 4); }
    function methodClass(m) {
        const map = {instance:'instance',register:'register',get:'get',make:'make',build:'build', 'get → build': 'get'};
        return 'ncp-m-' + (map[m] || 'get');
    }

    function renderTimeline(filter) {
    const el = document.getElementById('ncp-timeline');
    const filtered = filter ? data.filter(d => d.abstract.toLowerCase().includes(filter.toLowerCase())) : data;

    // Merge get → build pairs
    const merged = [];
    for (let i = 0; i < filtered.length; i++) {
        const curr = filtered[i];
        const next = filtered[i + 1];
        if (curr.method === 'get' && next && next.method === 'build' && curr.abstract === next.abstract) {
            merged.push({ ...curr, method: 'get → build' });
            i++;
        } else {
            merged.push(curr);
        }
    }

    el.innerHTML = merged.map(d => {
        const left  = (d.offset_ms / maxOffset * 100).toFixed(2);
        const width = Math.max(((d.duration_ms || 0.1) / maxOffset * 100), 0.3).toFixed(2);
        const dur   = d.duration_ms !== null ? d.duration_ms.toFixed(3) + 'ms' : '—';
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

    function renderTree(filter) {
        const el = document.getElementById('ncp-tree');
        const filtered = filter ? data.filter(d => d.abstract.toLowerCase().includes(filter.toLowerCase())) : data;
        el.innerHTML = filtered.map(d => {
            const indent = '<span style="color:#222">' + '│  '.repeat(Math.max(0,d.depth-1)) + (d.depth > 0 ? '├─ ' : '') + '</span>';
            const icon = d.cached ? '🟢' : (d.method === 'register' ? '🔵' : '🟡');
            return '<div class="ncp-tree-node">' + indent +
                '<span>' + icon + '</span>&nbsp;' +
                '<span style="color:#e0e0e0">' + d.short_name + '</span>' +
                '<span style="color:#333;margin-left:auto">' + (d.duration_ms !== null ? d.duration_ms.toFixed(3) + 'ms' : '') + '</span>' +
                '</div>';
        }).join('');
    }

    function renderTable(filter) {
        const el = document.getElementById('ncp-table-wrap');
        const filtered = filter ? data.filter(d => d.abstract.toLowerCase().includes(filter.toLowerCase())) : data;
        el.innerHTML = '<table class="ndb-table" style="width:100%"><thead><tr>' +
            '<th class="ndb-th">#</th><th class="ndb-th">Service</th><th class="ndb-th">Method</th><th class="ndb-th">Type</th><th class="ndb-th">Depth</th><th class="ndb-th">Offset</th><th class="ndb-th">Duration</th><th class="ndb-th">Status</th>' +
            '</tr></thead><tbody>' +
            filtered.map(d => {
                const status = d.cached
                    ? '<span style="color:#4ade80">cached</span>'
                    : (d.resolved ? '<span style="color:#fbbf24">built</span>' : '<span>pending</span>');
                return '<tr><td class="ndb-td">' + d.id + '</td>' +
                    '<td class="ndb-td" title="' + d.abstract + '">' + d.short_name + '</td>' +
                    '<td class="ndb-td"><span class="ncp-tl-method ' + methodClass(d.method) + '">' + d.method + '</span></td>' +
                    '<td class="ndb-td" >' + (d.type||'—') + '</td>' +
                    '<td class="ndb-td">' + d.depth + '</td>' +
                    '<td class="ndb-td">' + d.offset_ms.toFixed(1) + 'ms</td>' +
                    '<td class="ndb-td">' + (d.duration_ms !== null ? d.duration_ms.toFixed(3) + 'ms' : '—') + '</td>' +
                    '<td class="ndb-td">' + status + '</td></tr>';
            }).join('') + '</tbody></table>';
    }

    window.ncpSubTab = function(tab, el) {
        document.querySelectorAll('.ndb-sub-tab').forEach(t => t.classList.remove('active'));
        el.classList.add('active');
        document.getElementById('ncp-timeline').style.display  = tab === 'timeline' ? '' : 'none';
        document.getElementById('ncp-tree').style.display      = tab === 'tree' ? '' : 'none';
        document.getElementById('ncp-table-wrap').style.display = tab === 'table' ? '' : 'none';
    };

    let filterVal = '';
    window.ncpFilter = function(val) {
        filterVal = val;
        renderTimeline(val); renderTree(val); renderTable(val);
    };

    renderTimeline(); renderTree(); renderTable();
})();
</script>
HTML;
    }
}