/**
 * Nitro Livewire — standalone client runtime.
 *
 * Self-contained: no Alpine, no dependency on the HTMX runtime. It discovers
 * components by their wire:id root, wires the wire:* directives, batches commits
 * to the update endpoint, and morphs the returned HTML back into the DOM.
 */
(function () {
    'use strict';

    var CONFIG = (window.Livewire && window.Livewire.config) || {};
    var UPDATE_URI = CONFIG.updateUri || '/livewire/update';
    var UPLOAD_URI = CONFIG.uploadUri || '/livewire/upload';
    var CSRF = CONFIG.csrf || '';

    /** id -> { id, el, snapshot, dirty, $wire, watchers } */
    var components = {};

    /** wire: attribute bases that are NOT DOM events (everything else is treated as one). */
    var NON_EVENT = {
        model: 1, loading: 1, target: 1, dirty: 1, poll: 1, init: 1, key: 1,
        ignore: 1, navigate: 1, confirm: 1, offline: 1, show: 1, text: 1,
        transition: 1, replace: 1, current: 1, bind: 1, cloak: 1, snapshot: 1,
        id: 1, effects: 1, island: 1, region: 1, stream: 1, sort: 1, persist: 1, teleport: 1
    };

    /** Keyboard aliases for key modifiers (wire:keydown.enter, .esc, …). */
    var KEY_ALIASES = {
        enter: 'Enter', tab: 'Tab', esc: 'Escape', escape: 'Escape', space: ' ',
        up: 'ArrowUp', down: 'ArrowDown', left: 'ArrowLeft', right: 'ArrowRight',
        delete: 'Delete', backspace: 'Backspace', home: 'Home', end: 'End'
    };

    // ---- boot ---------------------------------------------------------------

    function start(root) {
        root = root || document;
        applyAssets(root);
        applyTeleports(root);
        root.querySelectorAll('[wire\\:id]').forEach(register);
    }

    function register(el) {
        var id = el.getAttribute('wire:id');
        if (!id || components[id]) return;
        var comp = { id: id, el: el, snapshot: parseSnapshot(el.getAttribute('wire:snapshot')), dirty: {}, watchers: {} };
        comp.$wire = makeWire(comp);
        components[id] = comp;
        bindModels(comp);
        bindEvents(comp);
        applyModelValues(comp);
        applyDynamicDirectives(comp);
        setLoading(comp, false, []); // start in the resting state — wire:loading hidden
        bindPoll(comp);
        bindInit(comp);
        bindLazy(comp);
        bindIslands(comp);
        runScripts(comp);
        uncloak(comp);
    }

    /** A lazy component paints a placeholder first — load its real body immediately. */
    function bindLazy(comp) {
        var memo = (comp.snapshot && comp.snapshot.memo) || {};
        if (memo.lazy) commit(comp, { calls: [{ method: '__lazyLoad', params: [] }] });
    }

    /** Re-scan a component after a morph so new elements get their directives bound. */
    function rebind(comp) {
        bindModels(comp);
        bindEvents(comp);
        bindPoll(comp);
        bindIslands(comp);
        applyModelValues(comp);
        applyDynamicDirectives(comp);
        runScripts(comp);
        uncloak(comp);
    }

    /** Populate wire:model inputs from the component's snapshot state. */
    function applyModelValues(comp) {
        var data = (comp.snapshot && comp.snapshot.data) || {};
        var active = document.activeElement;
        eachWith(comp.el, 'wire:model', function (el, a) {
            if (el === active) return; // don't clobber what the user is typing
            if (el.type === 'file') return; // file inputs can't be set programmatically
            var val = getPath(data, a.value); // supports nested paths (form.email)
            if (val === undefined) return;
            if (val !== null && typeof val === 'object') return; // synth tuples / arrays
            if (el.type === 'checkbox') el.checked = !!val;
            else if (el.type === 'radio') el.checked = (el.value === String(val));
            else el.value = (val === null) ? '' : val;
        });
    }

    /** Whether a value is a dehydrated [payload, {s:...}] synth tuple. */
    function isSynthTuple(v) {
        return Array.isArray(v) && v.length === 2 && v[1] && typeof v[1] === 'object' && v[1].s;
    }

    /** Read a possibly-dotted path (e.g. "form.email", "student.name") out of an object. */
    function getPath(obj, path) {
        return String(path).split('.').reduce(function (o, k) {
            if (o == null) return undefined;
            if (isSynthTuple(o)) o = o[0]; // descend into a model/collection payload
            return o[k];
        }, obj);
    }

    /** Evaluate a small wire:show/wire:text style expression (path, optional leading !). */
    function evalExpr(comp, expr) {
        expr = (expr || '').trim();
        var negate = false;
        while (expr.charAt(0) === '!') { negate = !negate; expr = expr.slice(1).trim(); }
        var val;
        if (expr === 'true') val = true;
        else if (expr === 'false') val = false;
        else val = getPath((comp.snapshot && comp.snapshot.data) || {}, expr);
        return negate ? !val : val;
    }

    function parseSnapshot(raw) {
        if (!raw) return {};
        try { return JSON.parse(raw); } catch (e) { return {}; }
    }

    function componentFor(el) {
        var root = el.closest && el.closest('[wire\\:id]');
        return root ? components[root.getAttribute('wire:id')] : null;
    }

    /** Find the full attribute on el that is `name` or `name.<modifiers>`. */
    function wireAttr(el, name) {
        if (!el.attributes) return null;
        for (var i = 0; i < el.attributes.length; i++) {
            var a = el.attributes[i].name;
            if (a === name || a.indexOf(name + '.') === 0) return el.attributes[i];
        }
        return null;
    }

    function mods(attrName, base) {
        return attrName === base ? [] : attrName.slice(base.length + 1).split('.');
    }

    function markBound(el) { return (el.__wire = el.__wire || { events: {}, model: false, poll: false }); }

    // ---- wire:model — the workhorse; 90% of forms live here -----------------

    /** Current value of a bound property: the pending dirty edit, else snapshot. */
    function currentModelValue(comp, prop) {
        if (Object.prototype.hasOwnProperty.call(comp.dirty, prop)) return comp.dirty[prop];
        return getPath((comp.snapshot && comp.snapshot.data) || {}, prop);
    }

    function bindModels(comp) {
        eachWith(comp.el, 'wire:model', function (el, a) {
            var b = markBound(el);
            if (b.model) return;
            b.model = true;

            var prop = a.value;
            if (el.type === 'file') { bindFileUpload(el, comp, prop); return; }

            var m = mods(a.name, 'wire:model');
            var live = m.indexOf('live') !== -1;
            // .lazy / .blur / .change sync on the `change` event instead of `input`.
            var onChange = m.indexOf('lazy') !== -1 || m.indexOf('blur') !== -1 || m.indexOf('change') !== -1;
            var number = m.indexOf('number') !== -1;
            var trim = m.indexOf('trim') !== -1;
            var fill = m.indexOf('fill') !== -1;
            var ms = debounceMs(m);
            var throttled = m.indexOf('throttle') !== -1;

            // Read the value to sync for THIS element, honouring .number / .trim
            // and the checkbox-array pattern (several boxes -> one array prop).
            var pull = function () {
                if (el.type === 'checkbox' && Array.isArray(currentModelValue(comp, prop))) {
                    // Value-collector: each checked box contributes its `value`.
                    var arr = (currentModelValue(comp, prop) || []).slice();
                    var i = arr.indexOf(el.value);
                    if (el.checked) { if (i === -1) arr.push(el.value); }
                    else if (i !== -1) { arr.splice(i, 1); }
                    return arr;
                }
                var v = coerceValue(readValue(el), number);
                if (trim && typeof v === 'string') v = v.trim();
                return v;
            };

            // .fill seeds the property from the input's initial value.
            if (fill) { comp.dirty[prop] = pull(); }

            var handler = function () {
                comp.dirty[prop] = pull();
                markDirty(comp);
                if (live) commit(comp, { region: regionOf(el), island: islandOf(el) });
            };
            var wrapped = throttled ? throttle(handler, ms || 250) : (ms ? debounce(handler, ms) : handler);
            el.addEventListener(onChange ? 'change' : 'input', wrapped);
        });
    }

    function coerceValue(v, number) {
        if (!number || v === '' || v == null) return v;
        var n = Number(v);
        return isNaN(n) ? v : n;
    }

    function debounceMs(m) {
        for (var i = 0; i < m.length; i++) {
            var ms = /^(\d+)ms$/.exec(m[i]), s = /^(\d+)s$/.exec(m[i]);
            if (ms) return +ms[1];
            if (s) return +s[1] * 1000;
            if (m[i] === 'debounce') return 250;
        }
        return 0;
    }

    function readValue(el) {
        if (el.type === 'checkbox') return el.checked;
        if (el.type === 'radio') return el.checked ? el.value : undefined;
        if (el.multiple && el.tagName === 'SELECT') {
            return Array.prototype.map.call(el.selectedOptions, function (o) { return o.value; });
        }
        return el.value;
    }

    // ---- wire:model on file inputs (uploads) --------------------------------

    function bindFileUpload(el, comp, prop) {
        el.addEventListener('change', function () {
            if (!el.files || !el.files.length) return;
            setLoading(comp, true, [prop]);
            uploadFiles(el.files, function (names) {
                var refs = names.map(function (n) { return 'livewire-file:' + n; });
                var updates = {};
                updates[prop] = el.multiple ? refs : (refs[0] || null);
                setLoading(comp, false, [prop]);
                commit(comp, { updates: updates });
            });
        });
    }

    function uploadFiles(fileList, done) {
        var fd = new FormData();
        for (var i = 0; i < fileList.length; i++) fd.append('files[]', fileList[i]);
        fetch(UPLOAD_URI, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF }, body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) { done(res.files || []); })
            .catch(function (err) { console.error('[Livewire] upload failed', err); done([]); });
    }

    // ---- wire:<event> (click, submit, keydown, change, blur, …) -------------

    function bindEvents(comp) {
        allEls(comp.el).forEach(function (el) {
            if (!el.attributes) return;
            for (var i = 0; i < el.attributes.length; i++) {
                var name = el.attributes[i].name;
                if (name.indexOf('wire:') !== 0) continue;
                var base = name.slice(5).split('.')[0];
                if (NON_EVENT[base]) continue;
                bindOneEvent(comp, el, el.attributes[i], base);
            }
        });
    }

    function bindOneEvent(comp, el, attr, event) {
        var b = markBound(el);
        var mkey = attr.name;
        if (b.events[mkey]) return;
        b.events[mkey] = true;

        var m = mods(attr.name, 'wire:' + event);
        var target = m.indexOf('window') !== -1 ? window : (m.indexOf('outside') !== -1 ? document : el);
        var expr = attr.value;
        var ms = debounceMs(m);
        var throttled = m.indexOf('throttle') !== -1;

        var run = function (e) {
            if (m.indexOf('self') !== -1 && e.target !== el) return;
            if (m.indexOf('outside') !== -1 && el.contains(e.target)) return;
            if (!keyMatches(e, m, event)) return;
            // wire:submit always cancels the native submit (matches Livewire).
            if (event === 'submit' || m.indexOf('prevent') !== -1) e.preventDefault();
            if (m.indexOf('stop') !== -1) e.stopPropagation();
            if (!confirmed(el)) return;

            var region = regionOf(el), island = islandOf(el);
            if (expr) commit(comp, { calls: [parseCall(expr, comp)], region: region, island: island });
            else commit(comp, { region: region, island: island });
        };

        var wrapped = throttled ? throttle(run, ms || 250) : (ms ? debounce(run, ms) : run);
        target.addEventListener(event, wrapped, { once: m.indexOf('once') !== -1 });
    }

    /** For keyboard events, honour key modifiers (.enter, .esc, .ctrl, …). */
    function keyMatches(e, m, event) {
        if (event.indexOf('key') !== 0 || typeof e.key === 'undefined') return true;
        var needShift = m.indexOf('shift') !== -1, needCtrl = m.indexOf('ctrl') !== -1;
        var needAlt = m.indexOf('alt') !== -1, needMeta = m.indexOf('meta') !== -1 || m.indexOf('cmd') !== -1;
        if (needShift && !e.shiftKey) return false;
        if (needCtrl && !e.ctrlKey) return false;
        if (needAlt && !e.altKey) return false;
        if (needMeta && !e.metaKey) return false;
        for (var i = 0; i < m.length; i++) {
            var want = KEY_ALIASES[m[i]];
            if (want && e.key !== want) return false;
        }
        return true;
    }

    function confirmed(el) {
        var a = wireAttr(el, 'wire:confirm');
        if (!a) return true;
        var message = a.value;
        if (mods(a.name, 'wire:confirm').indexOf('prompt') !== -1) {
            var parts = message.split('|');
            return window.prompt(parts[0]) === (parts[1] || '');
        }
        return window.confirm(message);
    }

    function parseCall(expr, comp) {
        expr = (expr || '').trim();
        var m = /^([a-zA-Z_$][\w$]*)\s*(?:\((.*)\))?$/.exec(expr);
        if (!m) return { method: expr, params: [] };
        return { method: m[1], params: m[2] ? parseArgs(m[2]) : [] };
    }

    function parseArgs(str) {
        try { return JSON.parse('[' + str.replace(/'/g, '"') + ']'); } catch (e) { return []; }
    }

    // ---- wire:init / wire:poll ---------------------------------------------

    function bindInit(comp) {
        var a = wireAttr(comp.el, 'wire:init');
        if (a) commit(comp, { calls: [parseCall(a.value, comp)] });
    }

    function bindPoll(comp) {
        eachWith(comp.el, 'wire:poll', function (el, a) {
            var b = markBound(el);
            if (b.poll) return;
            b.poll = true;

            var m = mods(a.name, 'wire:poll');
            var ms = pollMs(m);
            var keepAlive = m.indexOf('keep-alive') !== -1;
            var visibleOnly = m.indexOf('visible') !== -1;
            var call = a.value ? parseCall(a.value, comp) : null;
            setInterval(function () {
                if (!document.body.contains(el)) return; // element gone
                if (visibleOnly && document.hidden) return;
                if (document.hidden && !keepAlive && !visibleOnly) return;
                commit(comp, call ? { calls: [call] } : {});
            }, ms);
        });
    }

    function pollMs(m) {
        for (var i = 0; i < m.length; i++) {
            var ms = /^(\d+)ms$/.exec(m[i]), s = /^(\d+)s$/.exec(m[i]);
            if (ms) return +ms[1];
            if (s) return +s[1] * 1000;
        }
        return 2000;
    }

    // ---- @script blocks -----------------------------------------------------

    function runScripts(comp) {
        comp.el.querySelectorAll('script[type="text/nitro-script"]').forEach(function (s) {
            if (s.__ran) return;
            s.__ran = true;
            try { new Function('$wire', s.textContent)(comp.$wire); }
            catch (e) { console.error('[Livewire] @script failed', e); }
        });
    }

    // ---- commit -------------------------------------------------------------

    function commit(comp, payload) {
        var updates = payload.updates || comp.dirty || {};
        var calls = payload.calls || [];
        comp.dirty = {};

        var targets = calls.map(function (c) { return c.method; }).concat(Object.keys(updates));
        setLoading(comp, true, targets);

        return fetch(UPDATE_URI, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Livewire': '1', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ components: [{ snapshot: comp.snapshot, updates: updates, calls: calls, region: payload.region || null, island: payload.island || null }] })
        })
            .then(function (r) { return r.json(); })
            .then(function (res) { applyResponse(res); })
            .catch(function (err) { console.error('[Livewire] commit failed', err); })
            .then(function () { setLoading(comp, false, targets); clearDirty(comp); });
    }

    function applyResponse(res) {
        (res.components || []).forEach(function (result) {
            var id = result.snapshot && result.snapshot.memo && result.snapshot.memo.id;
            var comp = components[id];
            if (!comp) return;
            var prev = comp.snapshot;
            comp.snapshot = result.snapshot;
            var fx = result.effects || {};
            if (fx.redirect) { doRedirect(fx.redirect, fx.redirectUsingNavigate); return; }
            if (fx.region) { morphRegion(comp, fx.region.name, fx.region.html); rebind(comp); }
            else if (fx.html) { morph(comp.el, fx.html); rebind(comp); }
            (fx.dispatches || []).forEach(handleDispatch);
            fireWatchers(comp, prev);
            syncUrl(comp);
        });
    }

    function doRedirect(url, useNavigate) {
        if (useNavigate) navigate(url, true);
        else window.location.href = url;
    }

    /** Keep the query string in sync with the component's #[Url] properties. */
    function syncUrl(comp) {
        var memo = (comp.snapshot && comp.snapshot.memo) || {};
        var bindings = memo.url;
        if (!bindings) return;
        var data = (comp.snapshot && comp.snapshot.data) || {};
        var params = new URLSearchParams(location.search);
        var push = false;

        Object.keys(bindings).forEach(function (prop) {
            var b = bindings[prop];
            var key = b.as || prop;
            var val = getPath(data, prop);
            var def = b['default'];
            if (val === '' || val === null || val === undefined || String(val) === String(def)) {
                params['delete'](key);
            } else {
                params.set(key, val);
            }
            if (b.history) push = true;
        });

        var qs = params.toString();
        var next = location.pathname + (qs ? '?' + qs : '') + location.hash;
        if (next === location.pathname + location.search + location.hash) return;
        if (push) history.pushState({}, '', next);
        else history.replaceState({}, '', next);
    }

    // ---- events -------------------------------------------------------------

    function handleDispatch(d) {
        window.dispatchEvent(new CustomEvent(d.event, { detail: d.params || [] }));
        Object.keys(components).forEach(function (id) {
            var comp = components[id];
            var memo = (comp.snapshot && comp.snapshot.memo) || {};
            if (d.to && memo.name !== d.to) return;
            var method = memo.listeners && memo.listeners[d.event];
            if (method) commit(comp, { calls: [{ method: method, params: d.params || [] }] });
        });
    }

    // ---- wire:loading / wire:target -----------------------------------------

    function setLoading(comp, on, targets) {
        eachWith(comp.el, 'wire:loading', function (el, a) {
            var target = el.getAttribute('wire:target');
            if (target && targets.length && !targetMatch(target, targets)) return;
            var m = mods(a.name, 'wire:loading');
            var delay = loadingDelay(m);
            if (on && delay) {
                el.__loadingTimer = setTimeout(function () { applyLoading(el, true, m, a.value); }, delay);
            } else {
                if (el.__loadingTimer) { clearTimeout(el.__loadingTimer); el.__loadingTimer = null; }
                applyLoading(el, on, m, a.value);
            }
        });
    }

    function targetMatch(target, targets) {
        return target.split(',').some(function (t) { return targets.indexOf(t.trim()) !== -1; });
    }

    function loadingDelay(m) {
        if (m.indexOf('delay') === -1) return 0;
        if (m.indexOf('shortest') !== -1) return 50;
        if (m.indexOf('shorter') !== -1) return 100;
        if (m.indexOf('short') !== -1) return 150;
        if (m.indexOf('longer') !== -1) return 500;
        if (m.indexOf('longest') !== -1) return 1000;
        if (m.indexOf('long') !== -1) return 300;
        return 200;
    }

    function applyLoading(el, on, m, value) {
        var remove = m.indexOf('remove') !== -1;
        var show = remove ? !on : on;

        if (m.indexOf('class') !== -1) {
            (value || '').split(' ').filter(Boolean).forEach(function (c) { el.classList.toggle(c, show); });
        } else if (m.indexOf('attr') !== -1) {
            if (show) el.setAttribute(value, value); else el.removeAttribute(value);
        } else {
            el.style.display = show ? '' : 'none';
        }
    }

    // ---- wire:dirty ---------------------------------------------------------

    function markDirty(comp) { toggleDirty(comp, true); }
    function clearDirty(comp) { toggleDirty(comp, false); }
    function toggleDirty(comp, on) {
        eachWith(comp.el, 'wire:dirty', function (el, a) {
            var m = mods(a.name, 'wire:dirty');
            var remove = m.indexOf('remove') !== -1;
            var state = remove ? !on : on;
            if (m.indexOf('class') !== -1) {
                (a.value || '').split(' ').filter(Boolean).forEach(function (c) { el.classList.toggle(c, state); });
            } else if (m.indexOf('attr') !== -1) {
                if (state) el.setAttribute(a.value, a.value); else el.removeAttribute(a.value);
            } else {
                el.style.display = state ? '' : 'none';
            }
        });
    }

    // ---- wire:offline -------------------------------------------------------

    function applyOffline(offline) {
        document.querySelectorAll('*').forEach(function (el) {
            var a = wireAttr(el, 'wire:offline');
            if (!a) return;
            var m = mods(a.name, 'wire:offline');
            if (m.indexOf('class') !== -1) {
                (a.value || '').split(' ').filter(Boolean).forEach(function (c) { el.classList.toggle(c, offline); });
            } else if (m.indexOf('attr') !== -1) {
                if (offline) el.setAttribute(a.value, a.value); else el.removeAttribute(a.value);
            } else {
                el.style.display = offline ? '' : 'none';
            }
        });
    }
    window.addEventListener('online', function () { applyOffline(false); });
    window.addEventListener('offline', function () { applyOffline(true); });

    // ---- wire:show / wire:text / wire:current / wire:bind / wire:cloak ------

    function applyDynamicDirectives(comp) {
        eachWith(comp.el, 'wire:show', function (el, a) {
            var visible = !!evalExpr(comp, a.value);
            var m = mods(a.name, 'wire:show');
            if (m.indexOf('transition') !== -1) transition(el, visible);
            else el.style.display = visible ? '' : 'none';
        });
        eachWith(comp.el, 'wire:text', function (el, a) {
            var val = evalExpr(comp, a.value);
            el.textContent = (val == null) ? '' : val;
        });
        eachWith(comp.el, 'wire:current', function (el, a) {
            if (el.tagName !== 'A') return;
            var here = el.getAttribute('href') === location.pathname
                || (el.getAttribute('href') === location.pathname + location.search);
            (a.value || 'active').split(' ').filter(Boolean).forEach(function (c) { el.classList.toggle(c, here); });
        });
        allEls(comp.el).forEach(function (el) {
            if (!el.attributes) return;
            for (var i = 0; i < el.attributes.length; i++) {
                var name = el.attributes[i].name;
                if (name.indexOf('wire:bind:') !== 0) continue;
                var attr = name.slice('wire:bind:'.length);
                var val = evalExpr(comp, el.attributes[i].value);
                if (val === false || val == null) el.removeAttribute(attr);
                else el.setAttribute(attr, val === true ? attr : val);
            }
        });
    }

    /** Strip wire:cloak once a component is live (it hides content until then via CSS). */
    function uncloak(comp) {
        var els = comp.el.querySelectorAll('[wire\\:cloak]');
        Array.prototype.forEach.call(els, function (el) { el.removeAttribute('wire:cloak'); });
        if (comp.el.hasAttribute && comp.el.hasAttribute('wire:cloak')) comp.el.removeAttribute('wire:cloak');
    }

    // ---- wire:transition ----------------------------------------------------

    function transition(el, show) {
        if (show) {
            el.style.display = '';
            el.style.transition = 'opacity 150ms ease, transform 150ms ease';
            el.style.opacity = '0';
            requestAnimationFrame(function () { el.style.opacity = '1'; });
        } else {
            el.style.transition = 'opacity 150ms ease';
            el.style.opacity = '0';
            setTimeout(function () { el.style.display = 'none'; }, 150);
        }
    }

    // ---- $wire magic object -------------------------------------------------

    function makeWire(comp) {
        var api = {
            get: function (prop) { return getPath((comp.snapshot && comp.snapshot.data) || {}, prop); },
            set: function (prop, value, live) {
                var u = {}; u[prop] = value;
                comp.dirty[prop] = value;
                markDirty(comp);
                if (live === false) return;
                return commit(comp, { updates: u });
            },
            call: function (method) {
                var params = Array.prototype.slice.call(arguments, 1);
                return commit(comp, { calls: [{ method: method, params: params }] });
            },
            refresh: function () { return commit(comp, {}); },
            dispatch: function (event) { handleDispatch({ event: event, params: Array.prototype.slice.call(arguments, 1) }); },
            dispatchTo: function (name, event) {
                handleDispatch({ to: name, event: event, params: Array.prototype.slice.call(arguments, 2) });
            },
            watch: function (prop, cb) { (comp.watchers[prop] = comp.watchers[prop] || []).push(cb); },
            upload: function (prop, file, done) {
                uploadFiles([file], function (names) {
                    api.set(prop, 'livewire-file:' + names[0]).then(function () { if (done) done(names[0]); });
                });
            },
            entangle: function (prop) {
                return { get: function () { return api.get(prop); }, set: function (v) { return api.set(prop, v); } };
            },
            get el() { return comp.el; },
            get id() { return comp.id; },
            get $parent() { var p = comp.el.parentElement && comp.el.parentElement.closest('[wire\\:id]'); return p ? components[p.getAttribute('wire:id')].$wire : null; }
        };

        if (typeof Proxy === 'undefined') return api;
        return new Proxy(api, {
            get: function (t, key) {
                if (key in t) return t[key];
                if (typeof key !== 'string') return undefined;
                var data = (comp.snapshot && comp.snapshot.data) || {};
                if (key in data) return getPath(data, key);
                return function () { return api.call.apply(null, [key].concat(Array.prototype.slice.call(arguments))); };
            },
            set: function (t, key, val) { api.set(key, val); return true; }
        });
    }

    function fireWatchers(comp, prev) {
        var keys = Object.keys(comp.watchers);
        if (!keys.length) return;
        var oldData = (prev && prev.data) || {};
        var newData = (comp.snapshot && comp.snapshot.data) || {};
        keys.forEach(function (prop) {
            var before = getPath(oldData, prop), after = getPath(newData, prop);
            if (JSON.stringify(before) !== JSON.stringify(after)) {
                comp.watchers[prop].forEach(function (cb) { try { cb(after, before); } catch (e) { console.error(e); } });
            }
        });
    }

    // ---- DOM morphing (compact morphdom-style) ------------------------------

    function morph(from, html) {
        var tmp = document.createElement(from.parentNode ? from.parentNode.nodeName : 'div');
        tmp.innerHTML = (html || '').trim();
        var to = tmp.firstElementChild;
        if (to) morphEl(from, to);
    }

    function morphEl(from, to) {
        // A frozen island: the server sent a keep marker — leave the existing DOM.
        if (to.hasAttribute && to.hasAttribute('wire:island-keep')) return;
        if (from.hasAttribute && from.hasAttribute('wire:ignore')) {
            // .self ignores this element's attributes only, still morphing children.
            if (mods(wireAttr(from, 'wire:ignore').name, 'wire:ignore').indexOf('self') === -1) return;
        }
        if (from.hasAttribute && from.hasAttribute('wire:replace')) { from.replaceWith(to); return; }
        if (from.nodeName !== to.nodeName) { from.replaceWith(to); return; }
        syncAttributes(from, to);
        morphChildren(from, to);
    }

    function syncAttributes(from, to) {
        var active = document.activeElement;
        var isActiveInput = from === active && /^(INPUT|TEXTAREA|SELECT)$/.test(from.nodeName);

        for (var i = from.attributes.length - 1; i >= 0; i--) {
            var name = from.attributes[i].name;
            if (!to.hasAttribute(name)) from.removeAttribute(name);
        }
        for (var j = 0; j < to.attributes.length; j++) {
            var a = to.attributes[j];
            if (isActiveInput && a.name === 'value') continue;
            if (from.getAttribute(a.name) !== a.value) from.setAttribute(a.name, a.value);
        }
    }

    function keyOf(node) { return node.nodeType === 1 ? node.getAttribute('wire:key') : null; }

    function morphChildren(from, to) {
        var active = document.activeElement;
        var toChildren = Array.prototype.slice.call(to.childNodes);
        var keyed = {};
        Array.prototype.forEach.call(from.childNodes, function (n) { var k = keyOf(n); if (k) keyed[k] = n; });

        var fromIndex = 0;
        toChildren.forEach(function (toNode) {
            var key = keyOf(toNode);
            if (key && keyed[key]) {
                var match = keyed[key];
                if (match !== from.childNodes[fromIndex]) from.insertBefore(match, from.childNodes[fromIndex] || null);
                morphNode(match, toNode);
                fromIndex++;
                return;
            }
            var current = from.childNodes[fromIndex];
            if (!current) {
                from.appendChild(toNode.cloneNode(true));
            } else if (current === active && /^(INPUT|TEXTAREA|SELECT)$/.test(current.nodeName)) {
                // leave the focused field in place
            } else {
                morphNode(current, toNode);
            }
            fromIndex++;
        });

        while (from.childNodes.length > toChildren.length) from.removeChild(from.lastChild);
    }

    function morphNode(from, to) {
        // Leave nested component roots alone — they manage their own DOM/state.
        if (from.nodeType === 1 && from.hasAttribute('wire:id')) return;
        if (from.nodeType !== to.nodeType) { from.replaceWith(to.cloneNode(true)); return; }
        if (from.nodeType === 3) { if (from.textContent !== to.textContent) from.textContent = to.textContent; return; }
        if (from.nodeType === 1) morphEl(from, to);
    }

    // ---- wire:region (scoped partial updates) -------------------------------

    function regionOf(el) {
        var i = el.closest && el.closest('[wire\\:region]');
        return i ? i.getAttribute('wire:region') : null;
    }

    function morphRegion(comp, name, html) {
        var el = comp.el.querySelector('[wire\\:region="' + name + '"]');
        if (!el) { morph(comp.el, html); return; }
        var tmp = document.createElement('div');
        tmp.innerHTML = (html || '').trim();
        var to = tmp.firstElementChild;
        if (to) morphEl(el, to);
    }

    // ---- wire:island (isolated regions + lazy/defer loading) ----------------

    function islandOf(el) {
        var i = el.closest && el.closest('[wire\\:island]');
        return i ? i.getAttribute('wire:island') : null;
    }

    // Kick off deferred (on load) and lazy (on intersect) island loads.
    function bindIslands(comp) {
        comp.el.querySelectorAll('[wire\\:island-defer]').forEach(function (el) {
            if (el.__islandLoad) return;
            el.__islandLoad = true;
            var name = el.getAttribute('wire:island');
            commit(comp, { calls: [{ method: '__loadIsland', params: [name] }], island: name });
        });
        comp.el.querySelectorAll('[wire\\:island-lazy]').forEach(function (el) {
            if (el.__islandLoad) return;
            el.__islandLoad = true;
            var name = el.getAttribute('wire:island');
            var io = new IntersectionObserver(function (entries) {
                if (entries[0].isIntersecting) {
                    io.disconnect();
                    commit(comp, { calls: [{ method: '__loadIsland', params: [name] }], island: name });
                }
            });
            io.observe(el);
        });
    }

    // ---- wire:navigate (SPA page swaps) -------------------------------------

    // Prefetch cache. Entries: url -> { html: string|true, expires: ms-epoch|Infinity }.
    // `true` marks an in-flight fetch; a string is the cached HTML.
    var prefetched = {};

    // Client tuning injected by LivewireManager (config/livewire.php → navigate).
    function navConfig() {
        var c = (window.Livewire && window.Livewire.config && window.Livewire.config.navigate) || {};
        return {
            hoverDelayMs: typeof c.hoverDelayMs === 'number' ? c.hoverDelayMs : 60,
            cacheTtl: typeof c.cacheTtl === 'string' ? c.cacheTtl : '0s'
        };
    }

    // "30s" / "500ms" / "2m" / bare number (= seconds) -> milliseconds. 0/invalid -> 0.
    function parseDuration(v) {
        if (v == null || v === '') return 0;
        if (typeof v === 'number') return v;
        var m = String(v).trim().match(/^(\d+(?:\.\d+)?)\s*(ms|s|m)?$/);
        if (!m) return 0;
        var n = parseFloat(m[1]);
        return m[2] === 'ms' ? n : m[2] === 'm' ? n * 60000 : n * 1000;
    }

    // Value of the wire:navigate attribute carrying `hover` (e.g. "30s"), or ''.
    function hoverTtlValue(a) {
        for (var i = 0; i < a.attributes.length; i++) {
            var name = a.attributes[i].name;
            if (name.indexOf('wire:navigate') === 0 && name.indexOf('hover') !== -1) {
                return a.attributes[i].value;
            }
        }
        return '';
    }

    // Cached HTML for this url if still fresh, else null.
    function freshPrefetch(url) {
        var e = prefetched[url];
        return (e && typeof e.html === 'string' && Date.now() < e.expires) ? e.html : null;
    }

    // Fetch a link's HTML and cache it with the resolved TTL (per-link value wins,
    // else the config default; 0/"0s" caches until the next full page load).
    function prefetchLink(a) {
        var url = a.href;
        var e = prefetched[url];
        if (e && (e.html === true || Date.now() < e.expires)) return; // in-flight or still fresh
        var ttl = parseDuration(hoverTtlValue(a) || navConfig().cacheTtl);
        var expires = ttl > 0 ? Date.now() + ttl : Infinity;
        prefetched[url] = { html: true, expires: expires };
        fetch(url, { headers: { 'X-Livewire-Navigate': '1' } }).then(function (r) { return r.text(); })
            .then(function (html) { prefetched[url] = { html: html, expires: expires }; })
            .catch(function () { delete prefetched[url]; });
    }

    // The closest <a> opting into wire:navigate, whatever the modifier combo
    // (.hover / .preserve-scroll / .keydown / mixes of them).
    function navLink(target) {
        var a = target && target.closest && target.closest('a[href]');
        if (!a || a.hasAttribute('wire:navigate-ignore')) return null;
        for (var i = 0; i < a.attributes.length; i++) {
            if (a.attributes[i].name.indexOf('wire:navigate') === 0) return a;
        }
        return null;
    }

    function go(a) {
        navigate(a.href, true, navAttr(a).indexOf('preserve-scroll') !== -1);
    }

    // .keydown / .keypress opt a link into navigating on the initial PRESS
    // (pointer-down or Enter key-down) instead of waiting for the release.
    function navigatesEarly(a) {
        var m = navAttr(a);
        return m.indexOf('keydown') !== -1 || m.indexOf('keypress') !== -1;
    }

    // Normal wire:navigate links navigate on the release click.
    document.addEventListener('click', function (e) {
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
        var a = navLink(e.target);
        if (!a) return;
        e.preventDefault();
        go(a);
    });

    // Press-to-navigate: fire now, and swallow the click that follows the
    // release so it neither triggers a full page load nor navigates twice.
    function navigateOnPress(a) {
        var swallow = function (ev) { ev.preventDefault(); ev.stopPropagation(); };
        a.addEventListener('click', swallow, { once: true });
        setTimeout(function () { a.removeEventListener('click', swallow); }, 1000);
        go(a);
    }

    document.addEventListener('pointerdown', function (e) {
        if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = navLink(e.target);
        if (a && navigatesEarly(a)) navigateOnPress(a);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = navLink(e.target);
        if (a && navigatesEarly(a)) { e.preventDefault(); navigateOnPress(a); }
    });

    // Prefetch on hover for wire:navigate.hover links, after the configured hover
    // delay (Livewire's default is 60ms). Delegated mouseover/mouseout with a
    // per-link timer, so dynamically-added links work and a quick fly-past that
    // leaves before the delay elapses never fires a fetch.
    var hoverTimers = new WeakMap();
    document.addEventListener('mouseover', function (e) {
        var a = e.target.closest && e.target.closest('a');
        if (!a || navAttr(a).indexOf('hover') === -1 || hoverTimers.has(a) || freshPrefetch(a.href)) return;
        var t = setTimeout(function () { hoverTimers.delete(a); prefetchLink(a); }, navConfig().hoverDelayMs);
        hoverTimers.set(a, t);
    });
    document.addEventListener('mouseout', function (e) {
        var a = e.target.closest && e.target.closest('a');
        if (!a || !hoverTimers.has(a)) return;
        if (e.relatedTarget && a.contains(e.relatedTarget)) return; // moved within the link
        clearTimeout(hoverTimers.get(a));
        hoverTimers.delete(a);
    });

    function navAttr(a) {
        for (var i = 0; i < a.attributes.length; i++) {
            if (a.attributes[i].name.indexOf('wire:navigate') === 0) return mods(a.attributes[i].name, 'wire:navigate');
        }
        return [];
    }

    window.addEventListener('popstate', function () { navigate(location.href, false); });

    function navigate(url, push, preserveScroll) {
        var scrollY = window.scrollY;
        var cached = freshPrefetch(url);
        if (cached === null && prefetched[url]) delete prefetched[url]; // expired/stale — drop it
        var pre = cached !== null ? Promise.resolve(cached)
            : fetch(url, { headers: { 'X-Livewire-Navigate': '1' } }).then(function (r) { return r.text(); });

        // The shared NProgress bar (wired in the layout) listens for these.
        window.dispatchEvent(new CustomEvent('livewire:navigating'));
        pre.then(function (html) {
            var persisted = capturePersisted();
            var doc = new DOMParser().parseFromString(html, 'text/html');
            restorePersisted(doc, persisted);
            document.body.replaceWith(doc.body);
            document.title = doc.title;
            if (push) history.pushState({}, '', url);
            window.scrollTo(0, preserveScroll ? scrollY : 0);
            for (var k in components) delete components[k];
            start(document);
            window.dispatchEvent(new CustomEvent('livewire:navigated'));
        }).catch(function () {
            window.dispatchEvent(new CustomEvent('livewire:navigated'));
            window.location.href = url;
        });
    }

    // ---- @persist / @teleport -----------------------------------------------

    function capturePersisted() {
        var out = {};
        document.querySelectorAll('[wire\\:persist]').forEach(function (el) { out[el.getAttribute('wire:persist')] = el; });
        return out;
    }

    function restorePersisted(doc, persisted) {
        doc.querySelectorAll('[wire\\:persist]').forEach(function (placeholder) {
            var key = placeholder.getAttribute('wire:persist');
            if (persisted[key]) placeholder.replaceWith(persisted[key]);
        });
    }

    function applyTeleports(root) {
        root.querySelectorAll('[wire\\:teleport]').forEach(function (el) {
            var target = document.querySelector(el.getAttribute('wire:teleport'));
            if (target) target.appendChild(el);
        });
    }

    // ---- @assets (inject into <head> once) ----------------------------------

    var seenAssets = {};

    function applyAssets(root) {
        root.querySelectorAll('template[wire\\:assets]').forEach(function (tpl) {
            var html = tpl.innerHTML.trim();
            var key = hashString(html);
            tpl.remove();
            if (seenAssets[key]) return;
            seenAssets[key] = true;
            var holder = document.createElement('div');
            holder.innerHTML = html;
            Array.prototype.slice.call(holder.childNodes).forEach(function (node) {
                if (node.nodeName === 'SCRIPT') {
                    var s = document.createElement('script');
                    Array.prototype.forEach.call(node.attributes, function (a) { s.setAttribute(a.name, a.value); });
                    s.textContent = node.textContent;
                    document.head.appendChild(s);
                } else {
                    document.head.appendChild(node);
                }
            });
        });
    }

    function hashString(str) {
        var h = 0;
        for (var i = 0; i < str.length; i++) { h = ((h << 5) - h + str.charCodeAt(i)) | 0; }
        return h;
    }

    // ---- utils --------------------------------------------------------------

    function allEls(root) {
        var out = Array.prototype.slice.call(root.querySelectorAll('*'));
        out.unshift(root);
        return out;
    }

    function eachWith(root, base, cb) {
        Array.prototype.forEach.call(root.querySelectorAll('*'), function (el) {
            var a = wireAttr(el, base);
            if (a) cb(el, a);
        });
        var self = wireAttr(root, base);
        if (self) cb(root, self);
    }

    function debounce(fn, ms) {
        var t;
        return function () { var ctx = this, args = arguments; clearTimeout(t); t = setTimeout(function () { fn.apply(ctx, args); }, ms); };
    }

    function throttle(fn, ms) {
        var last = 0, timer;
        return function () {
            var ctx = this, args = arguments, now = Date.now();
            var remaining = ms - (now - last);
            if (remaining <= 0) { last = now; fn.apply(ctx, args); }
            else { clearTimeout(timer); timer = setTimeout(function () { last = Date.now(); fn.apply(ctx, args); }, remaining); }
        };
    }

    // ---- public surface -----------------------------------------------------

    window.Livewire = window.Livewire || {};
    window.Livewire.start = start;
    window.Livewire.commit = commit;
    window.Livewire.components = components;
    window.Livewire.find = function (id) { return components[id] ? components[id].$wire : null; };
    window.Livewire.first = function () { var k = Object.keys(components)[0]; return k ? components[k].$wire : null; };
    window.Livewire.dispatch = function (event) { handleDispatch({ event: event, params: Array.prototype.slice.call(arguments, 1) }); };
    window.Livewire.on = function (event, cb) { window.addEventListener(event, function (e) { cb.apply(null, e.detail || []); }); };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { start(document); });
    } else {
        start(document);
    }
})();
