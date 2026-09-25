/**
 * Nitro Livewire — standalone client runtime.
 *
 * Self-contained: no Alpine, no dependency on the HTMX runtime. It discovers
 * components by their wire:id root, wires the wire:* directives, batches commits
 * to the update endpoint, and morphs the returned HTML back into the DOM.
 *
 * Architecture:
 *   - Each component is a runtime object that OWNS its request state
 *     (one in-flight commit + a pooled `pending`) and its teardown disposers.
 *   - Events + models are bound ONCE at the component root via delegation,
 *     re-resolved from the DOM at dispatch time — so morphed-in nodes work
 *     with no per-element flags and no re-binding.
 *   - After a response we re-PROJECT state->DOM (model values, wire:show/text/
 *     bind, etc.); we no longer re-BIND events. Poll/island scans only touch
 *     genuinely new elements and register disposers.
 *
 * Delegation has three sharp edges, each handled explicitly below: non-bubbling
 * events must be caught in the capture phase AND scoped to their own target;
 * checkbox/radio/select fire both `input` and `change`, so exactly one of them
 * may own the sync; and .window/.outside handlers must not re-walk the subtree
 * on every tick of a high-frequency global event.
 */
(function () {
    'use strict';

    var CONFIG = (window.Livewire && window.Livewire.config) || {};
    var UPDATE_URI = CONFIG.updateUri || '/livewire/update';
    var UPLOAD_URI = CONFIG.uploadUri || '/livewire/upload';
    var CSRF = CONFIG.csrf || '';

    /** id -> component runtime object. */
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

    /**
     * Events that do NOT bubble. Two consequences, both handled in
     * attachEventDelegation/dispatchEventType: the root listener has to sit in
     * the CAPTURE phase (capture still traverses ancestors for these), and the
     * handler must fire for the event's own target only — walking up would run
     * an ancestor's wire:mouseenter every time a child was entered, which
     * binding directly to the element never did.
     */
    var CAPTURE_EVENTS = {
        blur: 1, focus: 1, mouseenter: 1, mouseleave: 1,
        pointerenter: 1, pointerleave: 1, scroll: 1, load: 1, error: 1, invalid: 1
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

        var comp = {
            id: id,
            el: el,
            snapshot: parseSnapshot(el.getAttribute('wire:snapshot')),
            dirty: {},          // model edits awaiting the next commit
            pending: null,      // pooled { updates, calls, region, island } while inflight
            inflight: null,     // the single in-flight commit promise, or null
            watchers: {},
            disposers: [],      // teardown callbacks (intervals, global listeners, observers)
            polled: new WeakSet(),
            islands: new WeakSet(),
            delegated: {},      // event type -> true (root listener attached)
            globals: {},        // "type:scope" -> true (window/outside listener attached)
            globalTargets: {}   // "type:scope" -> [{ el, name, mods }] resolved at scan time
        };
        comp.$wire = makeWire(comp);
        components[id] = comp;

        attachModelDelegation(comp);   // input / change / blur (once)
        attachEventDelegation(comp);   // wire:<event> (once, delegated)
        project(comp);                 // state -> DOM, plus poll/island/script scans
        setLoading(comp, false, []);   // resting state — wire:loading hidden
        bindInit(comp);
        bindLazy(comp);
    }

    /** A lazy component paints a placeholder first — load its real body immediately. */
    function bindLazy(comp) {
        var memo = (comp.snapshot && comp.snapshot.memo) || {};
        if (memo.lazy) commit(comp, { calls: [{ method: '__lazyLoad', params: [] }] });
    }

    function bindInit(comp) {
        var a = wireAttr(comp.el, 'wire:init');
        if (a) commit(comp, { calls: [parseCall(a.value)] });
    }

    /**
     * Re-project a component after a morph. NOTE: this no longer re-binds events
     * or models — delegation handles those for free. It only pushes fresh state
     * into the DOM and hooks up any genuinely new poll/island/script nodes.
     */
    function project(comp) {
        applyModelValues(comp);
        applyDynamicDirectives(comp);
        scanPoll(comp);
        scanIslands(comp);
        attachEventDelegation(comp);   // pick up new event *types*, refresh global targets
        runScripts(comp);
        uncloak(comp);
    }

    /** Tear a component down: stop timers, drop global listeners, release refs. */
    function teardown(comp) {
        comp.disposers.forEach(function (fn) { try { fn(); } catch (e) { /* noop */ } });
        comp.disposers = [];
        comp.inflight = null;
        comp.pending = null;
    }

    /** Remove components whose roots have left the DOM (e.g. conditional @if). */
    function sweepDetached() {
        Object.keys(components).forEach(function (id) {
            var comp = components[id];
            if (!document.contains(comp.el)) { teardown(comp); delete components[id]; }
        });
    }

    // ---- snapshot / path helpers -------------------------------------------

    function parseSnapshot(raw) {
        if (!raw) return {};
        try { return JSON.parse(raw); } catch (e) { return {}; }
    }

    function dataOf(comp) { return (comp.snapshot && comp.snapshot.data) || {}; }
    function memoOf(comp) { return (comp.snapshot && comp.snapshot.memo) || {}; }

    function componentFor(el) {
        var root = el.closest && el.closest('[wire\\:id]');
        return root ? components[root.getAttribute('wire:id')] : null;
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
        else val = getPath(dataOf(comp), expr);
        return negate ? !val : val;
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

    // ---- commit: per-component queue (serialized, pooled) -------------------
    //
    // The race the old free-function commit had: it read comp.snapshot and
    // cleared comp.dirty at *send* time, but the snapshot only advances when a
    // response lands. Two fast commits both built on snapshot N; the later
    // response clobbered the earlier. Fix: exactly one in-flight commit per
    // component. Anything requested while a commit is in flight is POOLED into
    // `comp.pending` and flushed — against the freshly-advanced snapshot — when
    // the in-flight one resolves.

    function commit(comp, payload) {
        payload = payload || {};
        var p = comp.pending || (comp.pending = { updates: {}, calls: [], region: null, island: null });
        if (payload.updates) for (var k in payload.updates) p.updates[k] = payload.updates[k];
        if (payload.calls) p.calls = p.calls.concat(payload.calls);
        if (payload.region) p.region = payload.region;
        if (payload.island) p.island = payload.island;
        return kick(comp);
    }

    // ---- 419: the page outlived its session ----------------------------------
    //
    // The CSRF token a page carries belongs to the session it was rendered
    // for. Once that session expires or is replaced (a login elsewhere), every
    // request the page sends is refused with 419 and an HTML error page — which
    // is not a snapshot, and is not JSON. Asked the way Livewire asks: the user
    // chooses to reload, rather than losing what they were typing to a reload
    // they did not ask for.

    var PAGE_EXPIRED = {};

    function handlePageExpiry() {
        if (window.confirm('This page has expired.\nWould you like to refresh the page?')) {
            window.location.reload();
        }
    }

    // The response body as JSON, unless the server said the page has expired.
    function readJson(response) {
        if (response.status === 419) {
            handlePageExpiry();
            throw PAGE_EXPIRED;
        }
        return response.json();
    }

    function kick(comp) {
        if (comp.inflight || !comp.pending) return comp.inflight || Promise.resolve();

        var p = comp.pending;
        comp.pending = null;

        // Fold pending model edits into this request's updates, then clear them.
        var updates = {};
        var key;
        for (key in comp.dirty) updates[key] = comp.dirty[key];
        for (key in p.updates) updates[key] = p.updates[key];
        comp.dirty = {};

        var calls = p.calls;
        var targets = calls.map(function (c) { return c.method; }).concat(Object.keys(updates));
        setLoading(comp, true, targets);

        var body = JSON.stringify({
            components: [{
                snapshot: comp.snapshot,
                updates: updates,
                calls: calls,
                region: p.region || null,
                island: p.island || null
            }]
        });

        var req = fetch(UPDATE_URI, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Livewire': '1', 'X-CSRF-TOKEN': CSRF },
            body: body
        })
            .then(readJson)
            .then(function (res) { applyResponse(res); })
            .catch(function (err) { if (err !== PAGE_EXPIRED) console.error('[Livewire] commit failed', err); })
            .then(function () {
                setLoading(comp, false, targets);
                clearDirty(comp);
                comp.inflight = null;
                if (comp.pending) kick(comp); // drain anything queued during the flight
            });

        comp.inflight = req;
        return req;
    }

    function applyResponse(res) {
        (res.components || []).forEach(function (result) {
            var id = result.snapshot && result.snapshot.memo && result.snapshot.memo.id;
            var comp = components[id];
            if (!comp) return;
            var prev = comp.snapshot;
            comp.snapshot = result.snapshot;   // advance BEFORE any queued re-kick uses it
            var fx = result.effects || {};
            if (fx.redirect) { doRedirect(fx.redirect, fx.redirectUsingNavigate); return; }
            if (fx.region) { morphRegion(comp, fx.region.name, fx.region.html); project(comp); }
            else if (fx.html) { morph(comp.el, fx.html); project(comp); }
            (fx.dispatches || []).forEach(handleDispatch);
            fireWatchers(comp, prev);
            syncUrl(comp);
        });
        sweepDetached();
    }

    function doRedirect(url, useNavigate) {
        if (useNavigate) navigate(url, true);
        else window.location.href = url;
    }

    // ---- wire:model — value plumbing ---------------------------------------

    /** Current value of a bound property: the pending dirty edit, else snapshot. */
    function currentModelValue(comp, prop) {
        if (Object.prototype.hasOwnProperty.call(comp.dirty, prop)) return comp.dirty[prop];
        return getPath(dataOf(comp), prop);
    }

    /** Populate wire:model inputs from the component's snapshot state. */
    function applyModelValues(comp) {
        var data = dataOf(comp);
        var active = document.activeElement;
        eachWith(comp.el, 'wire:model', function (el, a) {
            if (el === active) return;        // don't clobber what the user is typing
            if (el.type === 'file') return;   // file inputs can't be set programmatically

            // .fill seeds the property from the input's initial value (once).
            if (mods(a.name, 'wire:model').indexOf('fill') !== -1
                && !Object.prototype.hasOwnProperty.call(comp.dirty, a.value)
                && getPath(data, a.value) === undefined) {
                comp.dirty[a.value] = pullModel(comp, el, a);
                return;
            }

            var val = getPath(data, a.value);
            if (val === undefined) return;

            if (el.type === 'checkbox') {
                // Honour the checkbox-array pattern on the way IN, not just out.
                if (Array.isArray(val)) {
                    el.checked = val.map(String).indexOf(String(el.value)) !== -1;
                } else {
                    el.checked = !!val;
                }
            } else if (el.type === 'radio') {
                el.checked = (el.value === String(val));
            } else if (val !== null && typeof val === 'object') {
                return; // synth tuples / nested objects have no scalar input rep
            } else {
                el.value = (val === null) ? '' : val;
            }
        });
    }

    /** Read the value to sync for THIS element, honouring .number/.trim and checkbox-arrays. */
    function pullModel(comp, el, a) {
        var prop = a.value;
        var m = mods(a.name, 'wire:model');
        if (el.type === 'checkbox' && Array.isArray(currentModelValue(comp, prop))) {
            var arr = (currentModelValue(comp, prop) || []).slice();
            var i = arr.indexOf(el.value);
            if (el.checked) { if (i === -1) arr.push(el.value); }
            else if (i !== -1) { arr.splice(i, 1); }
            return arr;
        }
        var v = coerceValue(readValue(el), m.indexOf('number') !== -1);
        if (m.indexOf('trim') !== -1 && typeof v === 'string') v = v.trim();
        return v;
    }

    function coerceValue(v, number) {
        if (!number || v === '' || v == null) return v;
        var n = Number(v);
        return isNaN(n) ? v : n;
    }

    function readValue(el) {
        if (el.type === 'checkbox') return el.checked;
        if (el.type === 'radio') return el.checked ? el.value : undefined;
        if (el.multiple && el.tagName === 'SELECT') {
            return Array.prototype.map.call(el.selectedOptions, function (o) { return o.value; });
        }
        return el.value;
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

    /** Elements whose edits arrive as `change`, never `input`. */
    function isChangeDriven(el) {
        return el.type === 'checkbox' || el.type === 'radio' || el.tagName === 'SELECT';
    }

    // ---- wire:model delegation (input / change / blur, bound once) ----------
    //
    // Exactly ONE of the three handlers may own a given element, or a .live
    // binding commits twice per interaction: a checkbox click fires `input` AND
    // `change`, and a <select> does the same. isChangeDriven() is the split.

    function attachModelDelegation(comp) {
        var onInput = function (e) {
            var el = e.target, a = el && wireAttr(el, 'wire:model');
            if (!a || el.type === 'file') return;
            if (isChangeDriven(el)) return;   // owned by onChange
            var m = mods(a.name, 'wire:model');
            // .lazy / .blur / .change sync on `change`/`blur`, not `input`.
            if (m.indexOf('lazy') !== -1 || m.indexOf('blur') !== -1 || m.indexOf('change') !== -1) return;
            syncModel(comp, el, a, m);
        };
        var onChange = function (e) {
            var el = e.target, a = el && wireAttr(el, 'wire:model');
            if (!a) return;
            var m = mods(a.name, 'wire:model');
            if (el.type === 'file') { handleFileUpload(comp, el, a.value); return; }
            // .blur alone is owned by onBlur, even on a change-driven element.
            if (m.indexOf('blur') !== -1 && m.indexOf('lazy') === -1 && m.indexOf('change') === -1) return;
            if (m.indexOf('lazy') !== -1 || m.indexOf('change') !== -1 || isChangeDriven(el)) {
                syncModel(comp, el, a, m);
            }
        };
        var onBlur = function (e) {
            var el = e.target, a = el && el.nodeType === 1 && wireAttr(el, 'wire:model');
            if (!a) return;
            var m = mods(a.name, 'wire:model');
            if (m.indexOf('blur') !== -1) syncModel(comp, el, a, m);
        };

        addRootListener(comp, 'input', onInput);
        addRootListener(comp, 'change', onChange);
        addRootListener(comp, 'blur', onBlur, true); // capture — blur doesn't bubble
    }

    function syncModel(comp, el, a, m) {
        var prop = a.value;
        var live = m.indexOf('live') !== -1;
        var ms = debounceMs(m);
        var throttled = m.indexOf('throttle') !== -1;

        var fire = function () {
            comp.dirty[prop] = pullModel(comp, el, a);
            markDirty(comp);
            if (live) commit(comp, { region: regionOf(el), island: islandOf(el) });
        };

        if (throttled) perElThrottle(el, prop, fire, ms || 250);
        else if (ms) perElDebounce(el, prop, fire, ms);
        else fire();
    }

    // ---- wire:model on file inputs (uploads) --------------------------------

    function handleFileUpload(comp, el, prop) {
        if (!el.files || !el.files.length) return;
        setLoading(comp, true, [prop]);
        uploadFiles(el.files, function (names) {
            var refs = names.map(function (n) { return 'livewire-file:' + n; });
            var updates = {};
            updates[prop] = el.multiple ? refs : (refs[0] || null);
            setLoading(comp, false, [prop]);
            commit(comp, { updates: updates });
        });
    }

    function uploadFiles(fileList, done) {
        var fd = new FormData();
        for (var i = 0; i < fileList.length; i++) fd.append('files[]', fileList[i]);
        fetch(UPLOAD_URI, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF }, body: fd })
            .then(readJson)
            .then(function (res) { done(res.files || []); })
            .catch(function (err) {
                if (err !== PAGE_EXPIRED) console.error('[Livewire] upload failed', err);
                done([]);
            });
    }

    // ---- wire:<event> delegation (click, submit, keydown, blur, …) ----------
    //
    // One listener per event TYPE at the component root, resolved against the
    // DOM at dispatch. Morphed-in nodes just work; there is nothing to re-bind.
    // .window / .outside are inherently global, so they get one listener each on
    // window/document — against a CACHED target list, refreshed on every scan,
    // so a scroll/mousemove handler doesn't re-walk the subtree per tick.

    function eventTypesInUse(comp) {
        var rootTypes = {}, globals = {};
        allEls(comp.el).forEach(function (el) {
            if (!el.attributes) return;
            // Only elements that belong to THIS component (not a nested one).
            if (el !== comp.el && el.closest('[wire\\:id]') !== comp.el) return;
            for (var i = 0; i < el.attributes.length; i++) {
                var name = el.attributes[i].name;
                if (name.indexOf('wire:') !== 0) continue;
                var base = name.slice(5).split('.')[0];
                if (NON_EVENT[base]) continue;
                var m = mods(name, 'wire:' + base);
                var key = m.indexOf('window') !== -1 ? base + ':window'
                    : (m.indexOf('outside') !== -1 ? base + ':document' : null);
                if (key === null) { rootTypes[base] = 1; continue; }
                (globals[key] || (globals[key] = [])).push({ el: el, name: name, mods: m });
            }
        });
        return { rootTypes: rootTypes, globals: globals };
    }

    function attachEventDelegation(comp) {
        var used = eventTypesInUse(comp);

        // Refresh the .window/.outside target lists (register + after every morph).
        comp.globalTargets = used.globals;

        Object.keys(used.rootTypes).forEach(function (type) {
            if (comp.delegated[type]) return;
            comp.delegated[type] = true;
            addRootListener(comp, type, function (e) { dispatchEventType(comp, type, e); },
                !!CAPTURE_EVENTS[type]);
        });

        Object.keys(used.globals).forEach(function (key) {
            if (comp.globals[key]) return;
            comp.globals[key] = true;
            var parts = key.split(':');
            var type = parts[0], scope = parts[1]; // 'window' | 'document'
            var target = scope === 'window' ? window : document;
            var handler = function (e) { dispatchGlobal(comp, type, scope, e); };
            target.addEventListener(type, handler);
            comp.disposers.push(function () { target.removeEventListener(type, handler); });
        });
    }

    /** Walk target -> root, running any wire:<type> attrs, stopping at nested components. */
    function dispatchEventType(comp, type, e) {
        // A non-bubbling event belongs to its own target only: walking up would
        // fire an ancestor's wire:mouseenter every time a child was entered,
        // which binding directly to the element never did.
        var selfOnly = !!CAPTURE_EVENTS[type];
        var node = e.target;
        while (node) {
            if (node.nodeType === 1) {
                if (node !== comp.el && node.hasAttribute('wire:id')) return; // inner comp owns it
                if (node.attributes) {
                    for (var i = 0; i < node.attributes.length; i++) {
                        var attr = node.attributes[i];
                        if (attr.name === 'wire:' + type || attr.name.indexOf('wire:' + type + '.') === 0) {
                            var m = mods(attr.name, 'wire:' + type);
                            if (m.indexOf('window') !== -1 || m.indexOf('outside') !== -1) continue;
                            runEvent(comp, node, attr, type, m, e);
                        }
                    }
                }
            }
            if (node === comp.el || selfOnly) break;
            node = node.parentNode;
        }
    }

    function dispatchGlobal(comp, type, scope, e) {
        var targets = comp.globalTargets[type + ':' + scope];
        if (!targets) return;
        var wantOutside = scope === 'document';
        for (var i = 0; i < targets.length; i++) {
            var t = targets[i];
            if (!comp.el.contains(t.el)) continue;              // stale since the last scan
            if (wantOutside && t.el.contains(e.target)) continue;
            var value = t.el.getAttribute(t.name);
            if (value === null) continue;
            runEvent(comp, t.el, { name: t.name, value: value }, type, t.mods, e);
        }
    }

    function runEvent(comp, el, attr, event, m, e) {
        if (m.indexOf('self') !== -1 && e.target !== el) return;
        if (!keyMatches(e, m, event)) return;
        // wire:submit always cancels the native submit (matches Livewire).
        if (event === 'submit' || m.indexOf('prevent') !== -1) e.preventDefault();
        if (m.indexOf('stop') !== -1) e.stopPropagation();

        var fire = function () {
            if (m.indexOf('once') !== -1) {
                if (el.__wireOnce && el.__wireOnce[attr.name]) return;
                (el.__wireOnce || (el.__wireOnce = {}))[attr.name] = true;
            }
            if (!confirmed(el)) return;
            var region = regionOf(el), island = islandOf(el);
            if (attr.value) commit(comp, { calls: [parseCall(attr.value)], region: region, island: island });
            else commit(comp, { region: region, island: island });
        };

        var ms = debounceMs(m);
        var throttled = m.indexOf('throttle') !== -1;
        if (throttled) perElThrottle(el, '@' + attr.name, fire, ms || 250);
        else if (ms) perElDebounce(el, '@' + attr.name, fire, ms);
        else fire();
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

    // ---- call parsing -------------------------------------------------------

    function parseCall(expr) {
        expr = (expr || '').trim();
        var m = /^([a-zA-Z_$][\w$]*)\s*(?:\(([\s\S]*)\))?$/.exec(expr);
        if (!m) return { method: expr, params: [] };
        return { method: m[1], params: m[2] != null && m[2] !== '' ? parseArgs(m[2]) : [] };
    }

    /**
     * Robust argument parser. The old version was JSON.parse after a blind
     * '->" swap, which silently ate every call whose data contained an
     * apostrophe: save('O'Brien') became malformed JSON, caught, and the method
     * fired with NO args and no error. This tokenizes at the top level, so
     * quoted strings (with escapes) survive intact.
     */
    function parseArgs(str) {
        var args = [], i = 0, n = str.length;

        function skipWs() { while (i < n && /\s/.test(str.charAt(i))) i++; }
        function readString(q) {
            var s = ''; i++;
            while (i < n) {
                var c = str.charAt(i++);
                if (c === '\\') { s += str.charAt(i++); }
                else if (c === q) break;
                else s += c;
            }
            return s;
        }

        while (i < n) {
            skipWs();
            if (i >= n) break;
            var c = str.charAt(i);
            if (c === "'" || c === '"') {
                args.push(readString(c));
            } else {
                var depth = 0, buf = '';
                while (i < n) {
                    var ch = str.charAt(i);
                    if (ch === ',' && depth === 0) break;
                    if (ch === '[' || ch === '{') depth++;
                    if (ch === ']' || ch === '}') depth--;
                    buf += ch; i++;
                }
                args.push(coerceToken(buf.trim()));
            }
            skipWs();
            if (str.charAt(i) === ',') i++;
        }
        return args;
    }

    function coerceToken(t) {
        if (t === 'true') return true;
        if (t === 'false') return false;
        if (t === 'null') return null;
        if (t === '') return '';
        if (/^-?\d+(\.\d+)?$/.test(t)) return Number(t);
        var first = t.charAt(0), last = t.charAt(t.length - 1);
        if ((first === '[' && last === ']') || (first === '{' && last === '}')) {
            try { return JSON.parse(t); } catch (e) {
                try { return JSON.parse(t.replace(/'/g, '"')); } catch (e2) { return t; }
            }
        }
        return t; // bareword -> string
    }

    // ---- per-element debounce / throttle (delegation-friendly) --------------

    function perElDebounce(el, key, fn, ms) {
        var store = el.__wireDeb || (el.__wireDeb = {});
        clearTimeout(store[key]);
        store[key] = setTimeout(function () { store[key] = null; fn(); }, ms);
    }

    function perElThrottle(el, key, fn, ms) {
        var store = el.__wireThr || (el.__wireThr = {});
        var s = store[key] || (store[key] = { last: 0, timer: null });
        var now = Date.now(), remaining = ms - (now - s.last);
        if (remaining <= 0) { s.last = now; fn(); }
        else { clearTimeout(s.timer); s.timer = setTimeout(function () { s.last = Date.now(); fn(); }, remaining); }
    }

    // ---- wire:poll ----------------------------------------------------------
    //
    // Intervals are tracked and DISPOSED. The old code leaked one setInterval
    // per poll element forever — and after navigate() wiped `components`, the
    // closure kept firing against a dead component. Now each interval registers a
    // disposer and self-clears if its element leaves the DOM.

    function scanPoll(comp) {
        eachWith(comp.el, 'wire:poll', function (el, a) {
            if (comp.polled.has(el)) return;
            comp.polled.add(el);

            var m = mods(a.name, 'wire:poll');
            var ms = pollMs(m);
            var keepAlive = m.indexOf('keep-alive') !== -1;
            var visibleOnly = m.indexOf('visible') !== -1;
            var call = a.value ? parseCall(a.value) : null;

            var id = setInterval(function () {
                if (!document.body.contains(el)) { clearInterval(id); return; }
                if (visibleOnly && document.hidden) return;
                if (document.hidden && !keepAlive && !visibleOnly) return;
                commit(comp, call ? { calls: [call] } : {});
            }, ms);
            comp.disposers.push(function () { clearInterval(id); });
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

    // ---- events (server -> client dispatch) --------------------------------

    function handleDispatch(d) {
        window.dispatchEvent(new CustomEvent(d.event, { detail: d.params || [] }));
        Object.keys(components).forEach(function (id) {
            var comp = components[id];
            var memo = memoOf(comp);
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
        var show = m.indexOf('remove') !== -1 ? !on : on;
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
            var state = m.indexOf('remove') !== -1 ? !on : on;
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
            if (mods(a.name, 'wire:show').indexOf('transition') !== -1) transition(el, visible);
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
    //
    // The hide path used a bare setTimeout with no handle, so a show that
    // arrived inside the 150ms window still got slammed to display:none when the
    // stale timer fired. Every toggle now cancels the pending one.

    function transition(el, show) {
        if (el.__tTimer) { clearTimeout(el.__tTimer); el.__tTimer = null; }
        if (show) {
            el.style.display = '';
            el.style.transition = 'opacity 150ms ease, transform 150ms ease';
            el.style.opacity = '0';
            requestAnimationFrame(function () { el.style.opacity = '1'; });
        } else {
            el.style.transition = 'opacity 150ms ease';
            el.style.opacity = '0';
            el.__tTimer = setTimeout(function () { el.style.display = 'none'; el.__tTimer = null; }, 150);
        }
    }

    // ---- $wire magic object -------------------------------------------------

    function makeWire(comp) {
        var api = {
            get: function (prop) { return getPath(dataOf(comp), prop); },
            set: function (prop, value, live) {
                comp.dirty[prop] = value;
                markDirty(comp);
                if (live === false) return;
                var u = {}; u[prop] = value;
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
            get $parent() {
                var p = comp.el.parentElement && comp.el.parentElement.closest('[wire\\:id]');
                return p && components[p.getAttribute('wire:id')] ? components[p.getAttribute('wire:id')].$wire : null;
            }
        };

        if (typeof Proxy === 'undefined') return api;
        return new Proxy(api, {
            get: function (t, key) {
                if (key in t) return t[key];
                if (typeof key !== 'string') return undefined;
                var data = dataOf(comp);
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
        var newData = dataOf(comp);
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
    function sameKind(a, b) { return a.nodeType === b.nodeType && (a.nodeType !== 1 || a.nodeName === b.nodeName); }

    /**
     * Keyed-aware child reconciliation. The old version trimmed trailing nodes
     * by comparing `from.childNodes.length > toChildren.length`, which counts
     * text/comment nodes and ran AFTER keyed insertBefore reorders — so a
     * reordered keyed list could lop off the wrong tail. Now we track which
     * from-nodes were actually reused and remove precisely the leftovers.
     */
    function morphChildren(from, to) {
        var active = document.activeElement;
        var toChildren = Array.prototype.slice.call(to.childNodes);
        var fromKeyed = {};
        Array.prototype.forEach.call(from.childNodes, function (n) {
            var k = keyOf(n); if (k) fromKeyed[k] = n;
        });

        var used = [];             // parallels DOM nodes we've reused (no Set for old-engine safety)
        var isUsed = function (n) { return used.indexOf(n) !== -1; };
        var cursor = from.firstChild;

        toChildren.forEach(function (toNode) {
            var key = keyOf(toNode);
            var match = key && fromKeyed[key] ? fromKeyed[key] : null;

            if (match) {
                if (match !== cursor) from.insertBefore(match, cursor);
                else cursor = cursor.nextSibling;
                morphNode(match, toNode);
                used.push(match);
                return;
            }

            // No keyed match: reuse the cursor if it's a plain, unkeyed, same-kind
            // node that isn't the focused field; otherwise insert a fresh clone.
            if (cursor && !keyOf(cursor) && !isUsed(cursor) && sameKind(cursor, toNode)) {
                if (!(cursor === active && /^(INPUT|TEXTAREA|SELECT)$/.test(cursor.nodeName))) {
                    morphNode(cursor, toNode);
                }
                used.push(cursor);
                cursor = cursor.nextSibling;
            } else {
                var clone = toNode.cloneNode(true);
                from.insertBefore(clone, cursor);
                used.push(clone);
            }
        });

        // Remove any original children we didn't reuse.
        var n = from.firstChild;
        while (n) { var next = n.nextSibling; if (!isUsed(n)) from.removeChild(n); n = next; }
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

    function scanIslands(comp) {
        comp.el.querySelectorAll('[wire\\:island-defer]').forEach(function (el) {
            if (comp.islands.has(el)) return;
            comp.islands.add(el);
            var name = el.getAttribute('wire:island');
            commit(comp, { calls: [{ method: '__loadIsland', params: [name] }], island: name });
        });
        comp.el.querySelectorAll('[wire\\:island-lazy]').forEach(function (el) {
            if (comp.islands.has(el)) return;
            comp.islands.add(el);
            var name = el.getAttribute('wire:island');
            var io = new IntersectionObserver(function (entries) {
                if (entries[0].isIntersecting) {
                    io.disconnect();
                    commit(comp, { calls: [{ method: '__loadIsland', params: [name] }], island: name });
                }
            });
            io.observe(el);
            comp.disposers.push(function () { io.disconnect(); });
        });
    }

    // ---- wire:navigate (SPA page swaps) -------------------------------------

    // Prefetch cache. url -> { html: string|true, expires: ms-epoch|Infinity }.
    var prefetched = {};

    function navConfig() {
        var c = (window.Livewire && window.Livewire.config && window.Livewire.config.navigate) || {};
        return {
            hoverDelayMs: typeof c.hoverDelayMs === 'number' ? c.hoverDelayMs : 60,
            cacheTtl: typeof c.cacheTtl === 'string' ? c.cacheTtl : '0s'
        };
    }

    // "30s" / "500ms" / "2m" / bare number (= seconds) -> milliseconds.
    function parseDuration(v) {
        if (v == null || v === '') return 0;
        if (typeof v === 'number') return v;
        var m = String(v).trim().match(/^(\d+(?:\.\d+)?)\s*(ms|s|m)?$/);
        if (!m) return 0;
        var n = parseFloat(m[1]);
        return m[2] === 'ms' ? n : m[2] === 'm' ? n * 60000 : n * 1000;
    }

    function hoverTtlValue(a) {
        for (var i = 0; i < a.attributes.length; i++) {
            var name = a.attributes[i].name;
            if (name.indexOf('wire:navigate') === 0 && name.indexOf('hover') !== -1) return a.attributes[i].value;
        }
        return '';
    }

    function freshPrefetch(url) {
        var e = prefetched[url];
        return (e && typeof e.html === 'string' && Date.now() < e.expires) ? e.html : null;
    }

    function prefetchLink(a) {
        var url = a.href;
        var e = prefetched[url];
        if (e && (e.html === true || Date.now() < e.expires)) return;
        var ttl = parseDuration(hoverTtlValue(a) || navConfig().cacheTtl);
        var expires = ttl > 0 ? Date.now() + ttl : Infinity;
        prefetched[url] = { html: true, expires: expires };
        fetch(url, { headers: { 'X-Livewire-Navigate': '1' } }).then(function (r) { return r.text(); })
            .then(function (html) { prefetched[url] = { html: html, expires: expires }; })
            .catch(function () { delete prefetched[url]; });
    }

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

    function navigatesEarly(a) {
        var m = navAttr(a);
        return m.indexOf('keydown') !== -1 || m.indexOf('keypress') !== -1;
    }

    document.addEventListener('click', function (e) {
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
        var a = navLink(e.target);
        if (!a) return;
        e.preventDefault();
        go(a);
    });

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
        if (e.relatedTarget && a.contains(e.relatedTarget)) return;
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
        if (cached === null && prefetched[url]) delete prefetched[url];
        var pre = cached !== null ? Promise.resolve(cached)
            : fetch(url, { headers: { 'X-Livewire-Navigate': '1' } }).then(function (r) { return r.text(); });

        window.dispatchEvent(new CustomEvent('livewire:navigating'));
        pre.then(function (html) {
            var persisted = capturePersisted();
            var doc = new DOMParser().parseFromString(html, 'text/html');
            restorePersisted(doc, persisted);

            // Tear down every live component BEFORE swapping the body, so their
            // intervals and global (window/document) listeners are released.
            Object.keys(components).forEach(function (id) { teardown(components[id]); delete components[id]; });

            document.body.replaceWith(doc.body);
            document.title = doc.title;
            if (push) history.pushState({}, '', url);
            window.scrollTo(0, preserveScroll ? scrollY : 0);
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

    // ---- #[Url] sync --------------------------------------------------------

    function syncUrl(comp) {
        var bindings = memoOf(comp).url;
        if (!bindings) return;
        var data = dataOf(comp);
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

    /** Attach a root listener and register its disposer in one shot. */
    function addRootListener(comp, type, handler, capture) {
        comp.el.addEventListener(type, handler, !!capture);
        comp.disposers.push(function () { comp.el.removeEventListener(type, handler, !!capture); });
    }

    // ---- wire:current (active-link highlighting) ----------------------------

    function pathMatches(hrefUrl, actualUrl, options) {
        if (hrefUrl.hostname !== actualUrl.hostname) return false;
        var hrefPath = options.strict ? hrefUrl.pathname : hrefUrl.pathname.replace(/\/+$/, '');
        var actualPath = options.strict ? actualUrl.pathname : actualUrl.pathname.replace(/\/+$/, '');
        if (options.exact) return hrefPath === actualPath;
        var h = hrefPath.split('/'), a = actualPath.split('/');
        for (var i = 0; i < h.length; i++) { if (h[i] !== a[i]) return false; }
        return true;
    }

    function firstAttrStarting(el, prefix) {
        for (var i = 0; i < el.attributes.length; i++) {
            if (el.attributes[i].name.indexOf(prefix) === 0) return el.attributes[i];
        }
        return null;
    }

    function refreshCurrentLinks() {
        var url = new URL(window.location.href);
        Array.prototype.forEach.call(document.querySelectorAll('a[href]'), function (el) {
            var currentAttr = firstAttrStarting(el, 'wire:current');
            var navigates = firstAttrStarting(el, 'wire:navigate') !== null;
            if (!currentAttr && !navigates) return;

            var href = el.getAttribute('href');
            if (!href || href.charAt(0) === '#') return;

            var hrefUrl;
            try { hrefUrl = new URL(href, window.location.href); } catch (e) { return; }

            var m = currentAttr ? mods(currentAttr.name, 'wire:current') : [];
            if (m.indexOf('ignore') !== -1) return;

            var options = currentAttr
                ? { exact: m.indexOf('exact') !== -1, strict: m.indexOf('strict') !== -1 }
                : { exact: true, strict: false };

            var isCurrent = pathMatches(hrefUrl, url, options);
            var classes = currentAttr ? (currentAttr.value || '').split(' ').filter(Boolean) : [];

            if (isCurrent) {
                if (classes.length) el.classList.add.apply(el.classList, classes);
                el.setAttribute('data-current', '');
            } else {
                if (classes.length) el.classList.remove.apply(el.classList, classes);
                el.removeAttribute('data-current');
            }
        });
    }

    window.addEventListener('livewire:navigated', refreshCurrentLinks);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refreshCurrentLinks);
    } else {
        refreshCurrentLinks();
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
    window.Livewire.teardown = function (id) { if (components[id]) { teardown(components[id]); delete components[id]; } };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { start(document); });
    } else {
        start(document);
    }
})();
