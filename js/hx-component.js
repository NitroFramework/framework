/**
 * hx-component.js — Livewire-style component runtime for HTMX
 *
 * A translation layer, a thin bridge, that provides an ergonomic, developer-friendly API
 * surface on top of HTMX's native attribute system. Developers write
 * intuitive shorthand attributes (hx-click, hx-model, hx-loading, etc.)
 * and never think about the underlying HTMX wiring or HTTP routing as this layer translates
 * ergonomic component attributes into native HTMX behavior.
 *
 * Architecture: Compile-then-Delegate (two responsibilities)
 *
 *   COMPILE  — Walk the DOM and translate the developer-facing interface
 *              (ergonomic syntax like hx-model="name", hx-change="fetch")
 *              into native HTMX attributes (hx-trigger, hx-post, hx-target).
 *              This is a one-way translation — the original shorthand remains
 *              for readability, but HTMX only sees its own native attributes.
 *
 *   DELEGATE — Once compiled, all HTTP mechanics — request queuing, debounce
 *              timing, swap strategies, polling intervals — are delegated
 *              entirely to HTMX. This runtime never reimplements what HTMX
 *              already handles.
 *
 * The sole exception is hx-click, which requires custom execution for
 * action argument parsing (e.g. "delete(5)") and deferred value flushing.
 * Even then, the actual HTTP request is delegated to htmx.ajax().
 *
 * The result: ~500 lines of vanilla JS that give developers a Livewire-feel
 * API surface while keeping HTMX's stateless, server-rendered model intact.
 *
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ATTRIBUTES
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ── Context ──
 *
 *   hx-component="counter"              Component name (required on wrapper)
 *   hx-target="#foo"                    Override response target (inherited)
 *   hx-swap="innerHTML"                Override swap strategy (inherited)
 *
 * ── Actions ──
 *
 *   hx-click="increment"               Action on click (no args)
 *   hx-click="delete(5)"               Action with args
 *   hx-click="setTab(2, 'settings')"   Multiple typed args
 *   hx-confirm="Are you sure?"         Confirmation dialog before action
 *
 * ── Model binding ──
 *
 *   hx-model="name"                    Two-way bind → syncs on input (debounced)
 *   hx-model.lazy="name"              Two-way bind → syncs on change
 *   hx-model.defer="name"            Two-way bind → queues, sends with next action
 *   hx-debounce="500"                 Debounce delay in ms (default 300)
 *
 * ── Events ──
 *
 *   hx-change="fetchResults"           Fire action on change event
 *   hx-submit="save"                   Form submission → action with all fields
 *   hx-keydown.enter="search"          Fire action on keydown (with key filter)
 *   hx-keyup.escape="close"            Fire action on keyup (with key filter)
 *
 * ── Lifecycle ──
 *
 *   hx-init="loadData"                 Fire action on component mount
 *   hx-poll="5000"                     Auto-refresh every N ms
 *   hx-poll-action="refresh"           Action to call on poll (default: "render")
 *
 * ── Loading states ──
 *
 *   hx-loading="disabled"              Disable element during request
 *   hx-loading.class="opacity-50"      Add class during request
 *   hx-loading.remove.class="visible"  Remove class during request
 *
 * ── Dirty state ──
 *
 *   hx-dirty                            Element shown only when component has unsaved changes
 *                                        (CSS-driven via .hx-dirty class on component root)
 *
 * ── Toggle (client-side) ──
 *
 *   hx-toggle="#dropdown"               Toggle visibility of target element
 *   hx-toggle.class="active"           Toggle class on target (requires hx-toggle)
 *   hx-toggle.self                     Toggle on self instead of target
 *
 * ── Focus ──
 *
 *   hx-focus                            Auto-focus this element after swap
 *
 * ── Persistence ──
 *
 *   nitro-persist="region-id"           Preserve element across morph swaps
 *
 * ── Cross-component ──
 *
 *   hx-refresh="notifications"          Refresh another component after action
 *   hx-refresh="notifications, sidebar" Refresh multiple components after action
 *
 * ── Navigation ──
 *
 *   hx-navigate="/students/5"           SPA-style navigation (swaps body, pushState)
 *   hx-navigate-target="#app-content"   Override swap target (default: body)
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CONFIG
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *   window.HxComponent = {
 *       prefix: '/hx',           // route prefix (default: '/hx')
 *       method: 'POST',          // HTTP method (default: 'POST')
 *   };
 */
(function () {
  "use strict";

  // ── Config ──

  var config = window.HxComponent || {};
  var PREFIX = config.prefix || "/hx";
  var METHOD = (config.method || "POST").toUpperCase();

  // ═══════════════════════════════════════════════════════════════════════
  // SECTION 1: COMPILER
  //
  // Translates shorthand attributes into native HTMX attributes.
  // Runs at boot and after every swap (via htmx:afterSettle).
  // Idempotent — marks compiled elements with data-hx-compiled.
  // ═══════════════════════════════════════════════════════════════════════

  var COMPILED = "data-hx-compiled";

  function closestElement(node, selector) {
    var el = node;

    if (el && el.nodeType !== 1) {
      el = el.parentElement || null;
    }

    if (!el || typeof el.closest !== "function") {
      return null;
    }

    return el.closest(selector);
  }

  /**
   * Find the nearest hx-component ancestor and return its name.
   */
  function componentName(el) {
    var comp = closestElement(el, "[hx-component]");
    return comp ? comp.getAttribute("hx-component") : null;
  }

  /**
   * Build the action URL for a component.
   */
  function actionUrl(component, action) {
    return PREFIX + "/" + component + "/" + action;
  }

  /**
   * Check if this element (or an ancestor) already provides hx-target.
   */
  function hasInheritedAttr(el, attr) {
    return el.hasAttribute(attr) || !!closestElement(el, "[" + attr + "]");
  }

  /**
   * Set an attribute only if not already present on the element.
   */
  function setDefault(el, attr, value) {
    if (!el.hasAttribute(attr)) {
      el.setAttribute(attr, value);
    }
  }

  /**
   * Mark an element as compiled for a specific directive.
   * Uses a space-separated list so one element can be compiled for
   * multiple directives (e.g. hx-model and hx-change on the same element).
   */
  function markCompiled(el, directive) {
    var current = el.getAttribute(COMPILED) || "";
    var directives = current ? current.split(" ") : [];
    if (directives.indexOf(directive) === -1) {
      directives.push(directive);
      el.setAttribute(COMPILED, directives.join(" "));
    }
  }

  function isCompiled(el, directive) {
    var current = el.getAttribute(COMPILED) || "";
    return current.split(" ").indexOf(directive) !== -1;
  }

  // ── Compile: hx-model (default) ──

  function compileModel(root) {
    var els = root.querySelectorAll("[hx-model]");

    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (isCompiled(el, "hx-model")) continue;

      var property = el.getAttribute("hx-model");
      var comp = componentName(el);
      if (!comp) continue;

      var debounceMs = el.getAttribute("hx-debounce") || "300";

      setDefault(el, "name", property);
      el.setAttribute("hx-trigger", "input changed delay:" + debounceMs + "ms");
      el.setAttribute("hx-" + METHOD.toLowerCase(), actionUrl(comp, "update"));

      if (!hasInheritedAttr(el, "hx-target")) {
        el.setAttribute("hx-target", "closest [hx-component]");
      }
      if (!hasInheritedAttr(el, "hx-swap")) {
        el.setAttribute("hx-swap", "morph:outerHTML");
      }

      htmx.process(el);
      markCompiled(el, "hx-model");
    }
  }

  // ── Compile: hx-model.lazy ──

  function compileModelLazy(root) {
    var els = root.querySelectorAll("[hx-model\\.lazy]");

    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (isCompiled(el, "hx-model.lazy")) continue;

      var property = el.getAttribute("hx-model.lazy");
      var comp = componentName(el);
      if (!comp) continue;

      setDefault(el, "name", property);
      el.setAttribute("hx-trigger", "change");
      el.setAttribute("hx-" + METHOD.toLowerCase(), actionUrl(comp, "update"));

      if (!hasInheritedAttr(el, "hx-target")) {
        el.setAttribute("hx-target", "closest [hx-component]");
      }
      if (!hasInheritedAttr(el, "hx-swap")) {
        el.setAttribute("hx-swap", "morph:outerHTML");
      }

      htmx.process(el);
      markCompiled(el, "hx-model.lazy");
    }
  }

  // ── Compile: hx-init ──

  function compileInit(root) {
    var els = root.querySelectorAll("[hx-init]");

    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (isCompiled(el, "hx-init")) continue;

      var action = el.getAttribute("hx-init");
      var comp = componentName(el) || el.getAttribute("hx-component");
      if (!comp) continue;

      el.setAttribute("hx-trigger", "load");
      el.setAttribute("hx-" + METHOD.toLowerCase(), actionUrl(comp, action));

      if (!hasInheritedAttr(el, "hx-target")) {
        el.setAttribute("hx-target", "closest [hx-component]");
      }
      if (!hasInheritedAttr(el, "hx-swap")) {
        el.setAttribute("hx-swap", "morph:outerHTML");
      }

      htmx.process(el);
      markCompiled(el, "hx-init");
    }
  }

  // ── Compile: hx-poll ──

  function compilePoll(root) {
    var els = root.querySelectorAll("[hx-poll]");

    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (isCompiled(el, "hx-poll")) continue;

      var ms = parseInt(el.getAttribute("hx-poll"), 10);
      if (isNaN(ms) || ms < 500) {
        console.warn("[hx-component] hx-poll value must be >= 500ms");
        continue;
      }

      var comp = componentName(el) || el.getAttribute("hx-component");
      if (!comp) continue;

      var action = el.getAttribute("hx-poll-action") || "render";
      var seconds = ms / 1000 + "s";

      el.setAttribute("hx-trigger", "every " + seconds);
      el.setAttribute("hx-" + METHOD.toLowerCase(), actionUrl(comp, action));

      if (!hasInheritedAttr(el, "hx-target")) {
        el.setAttribute("hx-target", "closest [hx-component]");
      }
      if (!hasInheritedAttr(el, "hx-swap")) {
        el.setAttribute("hx-swap", "morph:outerHTML");
      }

      htmx.process(el);
      markCompiled(el, "hx-poll");
    }
  }

  // ── Compile: hx-submit ──

  function compileSubmit(root) {
    var els = root.querySelectorAll("[hx-submit]");

    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (isCompiled(el, "hx-submit")) continue;

      var action = el.getAttribute("hx-submit");
      var comp = componentName(el);
      if (!comp) continue;

      el.setAttribute("hx-trigger", "submit");
      el.setAttribute("hx-" + METHOD.toLowerCase(), actionUrl(comp, action));

      if (!hasInheritedAttr(el, "hx-target")) {
        el.setAttribute("hx-target", "closest [hx-component]");
      }
      if (!hasInheritedAttr(el, "hx-swap")) {
        el.setAttribute("hx-swap", "morph:outerHTML");
      }

      htmx.process(el);
      markCompiled(el, "hx-submit");
    }
  }

  // ── Compile: hx-change ──

  function compileChange(root) {
    var els = root.querySelectorAll("[hx-change]");

    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (isCompiled(el, "hx-change")) continue;

      var action = el.getAttribute("hx-change");
      var comp = componentName(el);
      if (!comp) continue;

      el.setAttribute("hx-trigger", "change");
      el.setAttribute("hx-" + METHOD.toLowerCase(), actionUrl(comp, action));
      el.setAttribute("hx-include", "this");

      if (!hasInheritedAttr(el, "hx-target")) {
        el.setAttribute("hx-target", "closest [hx-component]");
      }
      if (!hasInheritedAttr(el, "hx-swap")) {
        el.setAttribute("hx-swap", "morph:outerHTML");
      }

      htmx.process(el);
      markCompiled(el, "hx-change");
    }
  }

  // ── Compile: hx-keydown.{key} / hx-keyup.{key} ──

  var KEY_ALIASES = {
    enter: "Enter",
    escape: "Escape",
    esc: "Escape",
    space: " ",
    tab: "Tab",
    up: "ArrowUp",
    down: "ArrowDown",
    left: "ArrowLeft",
    right: "ArrowRight",
    backspace: "Backspace",
    delete: "Delete",
  };

  function compileKeyEvents(root) {
    var allEls = root.querySelectorAll("*");

    for (var i = 0; i < allEls.length; i++) {
      var el = allEls[i];
      var attrs = el.attributes;

      for (var j = 0; j < attrs.length; j++) {
        var name = attrs[j].name;
        var value = attrs[j].value;

        var match = name.match(/^hx-(keydown|keyup)\.(.+)$/);
        if (!match) continue;

        var directive = name;
        if (isCompiled(el, directive)) continue;

        var eventType = match[1];
        var keyFilter = match[2];
        var keyName = KEY_ALIASES[keyFilter] || keyFilter;

        var comp = componentName(el);
        if (!comp) continue;

        el.setAttribute("hx-trigger", eventType + "[key=='" + keyName + "']");
        el.setAttribute("hx-" + METHOD.toLowerCase(), actionUrl(comp, value));
        el.setAttribute("hx-include", "this");

        if (!hasInheritedAttr(el, "hx-target")) {
          el.setAttribute("hx-target", "closest [hx-component]");
        }
        if (!hasInheritedAttr(el, "hx-swap")) {
          el.setAttribute("hx-swap", "morph:outerHTML");
        }

        htmx.process(el);
        markCompiled(el, directive);
      }
    }
  }

  // ── Master compile ──

  function compile(root) {
    root = root || document.body;

    // ── Legacy hx-* ergonomic layer (kept as an escape hatch) ──
    compileModel(root);
    compileModelLazy(root);
    compileInit(root);
    compilePoll(root);
    compileSubmit(root);
    compileChange(root);
    compileKeyEvents(root);
  }

  // ═══════════════════════════════════════════════════════════════════════
  // SECTION 2: EXECUTE
  //
  // Handles hx-click — the one directive that needs custom JS because
  // of action parsing (args) and deferred value flushing.
  // ═══════════════════════════════════════════════════════════════════════

  // ── Deferred values queue (for hx-model.defer) ──

  var deferredValues = new WeakMap();

  function getDeferredQueue(compEl) {
    if (!deferredValues.has(compEl)) {
      deferredValues.set(compEl, {});
    }
    return deferredValues.get(compEl);
  }

  function queueDeferred(compEl, property, value) {
    getDeferredQueue(compEl)[property] = value;
  }

  function flushDeferred(compEl) {
    var queue = getDeferredQueue(compEl);
    var flushed = {};
    for (var key in queue) {
      if (queue.hasOwnProperty(key)) {
        flushed[key] = queue[key];
      }
    }
    deferredValues.set(compEl, {});
    compEl.classList.remove("hx-dirty");
    return flushed;
  }

  // ── Action string parser ──

  /**
   * Parse "increment" → { action: "increment", args: [] }
   * Parse "delete(5)" → { action: "delete", args: [5] }
   * Parse "setTab(2, 'settings')" → { action: "setTab", args: [2, "settings"] }
   */
  function parseAction(raw) {
    raw = raw.trim();
    var parenIndex = raw.indexOf("(");

    if (parenIndex === -1) {
      return { action: raw, args: [] };
    }

    var action = raw.substring(0, parenIndex).trim();
    var argsString = raw.substring(parenIndex + 1);

    var lastParen = argsString.lastIndexOf(")");
    if (lastParen !== -1) {
      argsString = argsString.substring(0, lastParen);
    }

    argsString = argsString.trim();
    if (argsString === "") {
      return { action: action, args: [] };
    }

    var jsonReady = "[" + argsString.replace(/'/g, '"') + "]";

    try {
      return { action: action, args: JSON.parse(jsonReady) };
    } catch (e) {
      console.warn(
        '[hx-component] Could not parse args for "' +
          raw +
          '", falling back to string split',
      );
      var parts = argsString.split(",");
      var fallback = [];
      for (var i = 0; i < parts.length; i++) {
        fallback.push(parts[i].trim().replace(/^['"]|['"]$/g, ""));
      }
      return { action: action, args: fallback };
    }
  }

  // ── Resolve attribute from element or nearest ancestor ──

  function resolveAttr(el, attr) {
    var node = closestElement(el, "[" + attr + "]");
    return node ? node.getAttribute(attr) : null;
  }

  // ── Get input value (checkboxes, radios, multi-selects) ──

  function getInputValue(el) {
    if (el.type === "checkbox") return el.checked;
    if (el.type === "radio") {
      var scope = el.closest("form") || document;
      var checked = scope.querySelector(
        'input[name="' + el.name + '"]:checked',
      );
      return checked ? checked.value : null;
    }
    if (el.tagName === "SELECT" && el.multiple) {
      var selected = [];
      for (var i = 0; i < el.options.length; i++) {
        if (el.options[i].selected) selected.push(el.options[i].value);
      }
      return selected;
    }
    return el.value;
  }

  // ── Core execute function ──

  function execute(triggerEl, action, extraValues) {
    extraValues = extraValues || {};

    var compEl = triggerEl.closest("[hx-component]");
    if (!compEl) {
      console.warn(
        '[hx-component] No hx-component found for action "' + action + '"',
      );
      return;
    }

    var compName = compEl.getAttribute("hx-component");
    var parsed = parseAction(action);
    var url = actionUrl(compName, parsed.action);

    // Start with deferred values
    var values = flushDeferred(compEl);

    // Add positional args
    if (parsed.args.length > 0) {
      values._args = JSON.stringify(parsed.args);
    }

    // Merge extra values
    for (var key in extraValues) {
      if (extraValues.hasOwnProperty(key)) {
        values[key] = extraValues[key];
      }
    }

    // Merge hx-vals from every ancestor that declares them, then the
    // trigger element itself last so its own values win on conflict.
    // This is what lets the component wrapper carry framework params
    // (_hxid, _hxfrags) without each button having to re-declare them.
    var valsEls = [];
    var walker = triggerEl;
    while (walker) {
      if (walker.hasAttribute && walker.hasAttribute("hx-vals")) {
        valsEls.unshift(walker); // outermost first
      }
      walker = walker.parentElement;
    }
    for (var vi = 0; vi < valsEls.length; vi++) {
      var raw = valsEls[vi].getAttribute("hx-vals");
      try {
        var parsed = JSON.parse(raw);
        for (var k in parsed) {
          if (parsed.hasOwnProperty(k)) values[k] = parsed[k];
        }
      } catch (err) {
        console.warn("[hx-component] Invalid hx-vals JSON:", raw);
      }
    }

    // Default target prefers the framework wrapper when present so swaps
    // replace the whole component (and its envelope), not just the inner
    // hx-component element. Falls back to the old default otherwise.
    var defaultTarget = triggerEl.closest("[data-hxid]")
      ? "closest [data-hxid]"
      : "closest [hx-component]";
    var target =
      resolveAttr(triggerEl, "hx-target") ||
      defaultTarget;
    var swap =
      resolveAttr(triggerEl, "hx-swap") ||
      "morph:outerHTML";

    var ajaxOptions = {
      source: triggerEl,
      target: target,
      swap: swap,
    };

    if (Object.keys(values).length > 0) {
      ajaxOptions.values = values;
    }

    htmx.ajax(METHOD, url, ajaxOptions);
  }

  // ── hx-click handler ──

  document.body.addEventListener("click", function (e) {
    var el = closestElement(e.target, "[hx-click]");
    if (!el) return;

    var confirmMsg = el.getAttribute("hx-confirm");
    if (confirmMsg && !confirm(confirmMsg)) return;

    e.preventDefault();
    execute(el, el.getAttribute("hx-click"));

    // hx-refresh="notifications, sidebar"
    var refresh = el.getAttribute("hx-refresh");
    if (refresh) {
    var compEl = closestElement(el, "[hx-component]");
      var target = compEl || el;

      target.addEventListener(
        "htmx:afterSettle",
        function () {
          var names = refresh.split(",");
          for (var i = 0; i < names.length; i++) {
            var name = names[i].trim();
            if (!name) continue;
            var other = document.querySelector('[hx-component="' + name + '"]');
            if (other) execute(other, "render");
          }
        },
        { once: true },
      );
    }
  });

  // ── hx-model.defer handler (queue only, no request) ──

  document.body.addEventListener("input", function (e) {
    var el = closestElement(e.target, "[hx-model\\.defer]");
    if (!el) return;

    var property = el.getAttribute("hx-model.defer");
    var compEl = closestElement(el, "[hx-component]");
    if (compEl && property) {
      queueDeferred(compEl, property, getInputValue(el));
      compEl.classList.add("hx-dirty");
    }
  });

  // ── hx-toggle handler (client-side only, no server request) ──

  document.body.addEventListener("click", function (e) {
    var el = closestElement(e.target, "[hx-toggle]");
    if (!el) return;

    e.preventDefault();

    var self = el.hasAttribute("hx-toggle.self");
    var cls = el.getAttribute("hx-toggle.class");

    var targetEl;
    if (self) {
      targetEl = el;
    } else {
      var selector = el.getAttribute("hx-toggle");
      targetEl = selector ? document.querySelector(selector) : null;
    }

    if (!targetEl) {
      console.warn(
        "[hx-component] hx-toggle target not found:",
        el.getAttribute("hx-toggle"),
      );
      return;
    }

    if (cls) {
      targetEl.classList.toggle(cls);
    } else {
      targetEl.classList.toggle("hx-hidden");
    }
  });

  // ═══════════════════════════════════════════════════════════════════════
  // SECTION 3: LOADING STATES
  //
  // Thin layer on top of HTMX's htmx:beforeRequest / htmx:afterRequest.
  // Handles hx-loading="disabled", hx-loading.class, hx-loading.remove.class
  // ═══════════════════════════════════════════════════════════════════════

  function applyLoadingStates(compEl, isLoading) {
    var els = compEl.querySelectorAll("[hx-loading]");

    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      var base = el.getAttribute("hx-loading");

      if (base === "disabled") {
        el.disabled = isLoading;
      }
    }

    // hx-loading.class="opacity-50 pointer-events-none"
    var clsEls = compEl.querySelectorAll("[hx-loading\\.class]");
    for (var j = 0; j < clsEls.length; j++) {
      var classes = clsEls[j].getAttribute("hx-loading.class").split(/\s+/);
      for (var k = 0; k < classes.length; k++) {
        if (!classes[k]) continue;
        if (isLoading) {
          clsEls[j].classList.add(classes[k]);
        } else {
          clsEls[j].classList.remove(classes[k]);
        }
      }
    }

    // hx-loading.remove.class="visible"
    var rmEls = compEl.querySelectorAll("[hx-loading\\.remove\\.class]");
    for (var m = 0; m < rmEls.length; m++) {
      var rmClasses = rmEls[m]
        .getAttribute("hx-loading.remove.class")
        .split(/\s+/);
      for (var n = 0; n < rmClasses.length; n++) {
        if (!rmClasses[n]) continue;
        if (isLoading) {
          rmEls[m].classList.remove(rmClasses[n]);
        } else {
          rmEls[m].classList.add(rmClasses[n]);
        }
      }
    }
  }

  // Listen on component-level requests
  document.body.addEventListener("htmx:beforeRequest", function (e) {
    var compEl = closestElement(e.target, "[hx-component]");
    if (compEl) applyLoadingStates(compEl, true);
  });

  document.body.addEventListener("htmx:afterRequest", function (e) {
    var compEl = closestElement(e.target, "[hx-component]");
    if (compEl) applyLoadingStates(compEl, false);
  });

  // ═══════════════════════════════════════════════════════════════════════
  // SECTION 4: PERSIST REGIONS
  //
  // Preserves elements marked with nitro-persist across morph swaps,
  // including focus state and cursor/selection position.
  // ═══════════════════════════════════════════════════════════════════════

  document.body.addEventListener("htmx:beforeSwap", function (e) {
    var target = e.detail.target;
    if (!target || !target.querySelectorAll) return;

    var persistEls = target.querySelectorAll("[nitro-persist]");
    if (persistEls.length === 0) return;

    var snapshots = [];

    for (var i = 0; i < persistEls.length; i++) {
      var focused = persistEls[i].contains(document.activeElement);
      var focusedEl = focused ? document.activeElement : null;

      snapshots.push({
        id: persistEls[i].getAttribute("nitro-persist"),
        el: persistEls[i],
        focusedEl: focusedEl,
        selectionStart:
          focusedEl && focusedEl.setSelectionRange
            ? focusedEl.selectionStart
            : null,
        selectionEnd:
          focusedEl && focusedEl.setSelectionRange
            ? focusedEl.selectionEnd
            : null,
      });
    }

    target.addEventListener(
      "htmx:afterSwap",
      function () {
        for (var i = 0; i < snapshots.length; i++) {
          var snap = snapshots[i];
          var placeholder = document.querySelector(
            '[nitro-persist="' + snap.id + '"]',
          );

          if (placeholder && snap.el !== placeholder) {
            placeholder.parentNode.replaceChild(snap.el, placeholder);
          }

          if (snap.focusedEl) {
            snap.focusedEl.focus();
            if (snap.selectionStart !== null) {
              snap.focusedEl.setSelectionRange(
                snap.selectionStart,
                snap.selectionEnd,
              );
            }
          }
        }
      },
      { once: true },
    );
  });

  // ═══════════════════════════════════════════════════════════════════════
  // SECTION 5: CSS UTILITIES
  // ═══════════════════════════════════════════════════════════════════════

  var style = document.createElement("style");
  style.textContent = [
    ".hx-loading-hide { display: none !important; }",
    ".hx-loading-show { display: revert !important; }",
    '[hx-loading="show"] { display: none; }',
    "[hx-component]:not(.hx-dirty) [hx-dirty] { display: none; }",
    ".hx-hidden { display: none !important; }",
  ].join("\n");
  document.head.appendChild(style);

  // ═══════════════════════════════════════════════════════════════════════
  // SECTION 6: NAVIGATION
  //
  // Snapshot-cached navigation for shared hx links. First visit remains SSR,
  // subsequent visits restore cached HTML instantly and revalidate in the
  // background. hx-navigate remains supported and feeds into the same runtime.
  // ═══════════════════════════════════════════════════════════════════════

  var navCache = new Map();
  var inflightNavigations = new Map();
  var NAV_CACHE_LIMIT = 24;

  function normalizeUrl(url) {
    return new URL(url, window.location.href).toString();
  }

  function splitSelectors(value) {
    if (!value) return [];
    var selectors = value.split(",");
    var result = [];

    for (var i = 0; i < selectors.length; i++) {
      var selector = selectors[i].trim();
      if (selector) result.push(selector);
    }

    return result;
  }

  function getNavigationRootElement() {
    return document.querySelector("[data-nitro-nav-root]");
  }

  function readBooleanAttribute(el, name, fallback) {
    if (!el) return fallback;

    var value = el.getAttribute(name);
    if (value === null || value === "") return fallback;

    return value !== "false";
  }

  function getDefaultNavigationContext() {
    var root = getNavigationRootElement();
    var targetSelector = root
      ? root.getAttribute("data-nitro-nav-root") || "[data-nitro-nav-root]"
      : null;

    return {
      targetSelector: targetSelector,
      selectSelector: targetSelector,
      oobSelectors: root
        ? splitSelectors(root.getAttribute("data-nitro-nav-oob") || "")
        : [],
      shouldCache: readBooleanAttribute(root, "data-nitro-nav-cache", true),
      shouldPrefetch: readBooleanAttribute(root, "data-nitro-nav-prefetch", true),
    };
  }

  function isPrimaryClick(event) {
    return (
      event.button === 0 &&
      !event.metaKey &&
      !event.ctrlKey &&
      !event.shiftKey &&
      !event.altKey
    );
  }

  function shouldHandleNavigation(el) {
    if (!el) return false;
    if (el.getAttribute("target") === "_blank") return false;
    if (el.hasAttribute("download")) return false;

    // Opt-in navigation: only links explicitly marked for HTMX nav are
    // intercepted (x-hx-link renders data-nitro-nav; hx-navigate is the raw
    // form). Every other link — plain <a>, external — is left to the browser
    // or its own layer.
    if (!el.hasAttribute("data-nitro-nav") && !el.hasAttribute("hx-navigate")) return false;

    var href =
      el.getAttribute("href") ||
      el.getAttribute("hx-navigate");
    if (!href || href.charAt(0) === "#") return false;

    var absolute = new URL(href, window.location.href);
    if (absolute.origin !== window.location.origin) return false;

    return true;
  }

  function resolveNavigationMeta(el) {
    var defaults = getDefaultNavigationContext();
    var targetSelector =
      el.getAttribute("data-nitro-nav-target") ||
      el.getAttribute("hx-navigate-target") ||
      el.getAttribute("hx-target") ||
      defaults.targetSelector;

    return {
      url: normalizeUrl(el.getAttribute("data-nitro-nav-url") || el.getAttribute("hx-navigate") || el.getAttribute("href")),
      targetSelector: targetSelector,
      selectSelector:
        el.getAttribute("data-nitro-nav-select") ||
        el.getAttribute("hx-select") ||
        targetSelector,
      oobSelectors: splitSelectors(
        el.getAttribute("data-nitro-nav-select-oob") ||
          el.getAttribute("hx-select-oob") ||
          defaults.oobSelectors.join(","),
      ),
      shouldPush:
        (el.getAttribute("data-nitro-nav-push") || "true") !== "false",
      shouldCache: readBooleanAttribute(
        el,
        "data-nitro-nav-cache",
        defaults.shouldCache,
      ),
      shouldPrefetch: readBooleanAttribute(
        el,
        "data-nitro-nav-prefetch",
        defaults.shouldPrefetch,
      ),
    };
  }

  function getTarget(meta) {
    return document.querySelector(meta.targetSelector);
  }

  function startNavigationProgress(token) {
    // bubbles:true so listeners bound to `document` (which survive a Livewire
    // body swap) receive it — a document.body-scoped listener would be lost.
    document.body.dispatchEvent(
      new CustomEvent("nitro:navigation-start", {
        bubbles: true,
        detail: { token: token },
      }),
    );
  }

  function finishNavigationProgress(token) {
    document.body.dispatchEvent(
      new CustomEvent("nitro:navigation-end", {
        bubbles: true,
        detail: { token: token },
      }),
    );
  }

  function touchNavCache(url, snapshot) {
    if (!snapshot) return;

    if (navCache.has(url)) {
      navCache.delete(url);
    }

    navCache.set(url, snapshot);

    while (navCache.size > NAV_CACHE_LIMIT) {
      var oldest = navCache.keys().next();
      if (oldest.done) break;
      navCache.delete(oldest.value);
    }
  }

  function collectOobHtml(selectors, source) {
    var items = {};

    for (var i = 0; i < selectors.length; i++) {
      var selector = selectors[i];
      var node = source.querySelector(selector);
      if (node) {
        items[selector] = node.outerHTML;
      }
    }

    return items;
  }

  function decodeNavigationTitle(value) {
    if (!value) return null;

    try {
      return decodeURIComponent(value);
    } catch (error) {
      return value;
    }
  }

  function parseSnapshotFromDocument(doc, meta, url, title) {
    var selected = doc.querySelector(meta.selectSelector);
    if (!selected) {
      throw new Error(
        "[hx-component] Navigation selector not found: " + meta.selectSelector,
      );
    }

    return {
      url: url,
      targetSelector: meta.targetSelector,
      selectSelector: meta.selectSelector,
      title: title || doc.title || document.title,
      html: selected.innerHTML,
      oob: collectOobHtml(meta.oobSelectors, doc),
    };
  }

  function currentPageSnapshot(meta) {
    var target = getTarget(meta);
    if (!target) return null;

    return {
      url: normalizeUrl(window.location.href),
      targetSelector: meta.targetSelector,
      selectSelector: meta.selectSelector,
      title: document.title,
      html: target.innerHTML,
      oob: collectOobHtml(meta.oobSelectors, document),
    };
  }

  function replaceOuterHtml(selector, html) {
    var existing = document.querySelector(selector);
    if (!existing) return;

    var template = document.createElement("template");
    template.innerHTML = html.trim();
    var next = template.content.firstElementChild;
    if (!next) return;

    existing.replaceWith(next);
  }

  function applySnapshot(snapshot, options) {
    var target = document.querySelector(snapshot.targetSelector);
    if (!target) {
      console.warn(
        "[hx-component] Navigation target not found:",
        snapshot.targetSelector,
      );
      return false;
    }

    target.innerHTML = snapshot.html;
    document.title = snapshot.title;

    for (var selector in snapshot.oob) {
      if (snapshot.oob.hasOwnProperty(selector)) {
        replaceOuterHtml(selector, snapshot.oob[selector]);
      }
    }

    compile(target);

    var focusEl = target.querySelector("[hx-focus]");
    if (focusEl) focusEl.focus();

    document.body.dispatchEvent(
      new CustomEvent("nitro:navigation", {
        bubbles: true,
        detail: {
          url: snapshot.url,
          cached: !!(options && options.cached),
          revalidated: !!(options && options.revalidated),
          popstate: !!(options && options.popstate),
        },
      }),
    );

    return true;
  }

  function fetchSnapshot(meta) {
    if (inflightNavigations.has(meta.url)) {
      return inflightNavigations.get(meta.url);
    }

    var request = fetch(meta.url, {
      method: "GET",
      credentials: "same-origin",
      headers: {
        Accept: "text/html,application/xhtml+xml",
        "X-Nitro-Navigate": "true",
        "X-Nitro-Select": meta.selectSelector,
        "X-Nitro-Select-Oob": meta.oobSelectors.join(","),
      },
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error(
            "[hx-component] Navigation request failed: " +
              response.status +
              " " +
              response.statusText,
          );
        }

        return response.text().then(function (html) {
          var parser = new DOMParser();
          var doc = parser.parseFromString(html, "text/html");
          var finalUrl = normalizeUrl(response.url || meta.url);
          var snapshot = parseSnapshotFromDocument(
            doc,
            meta,
            finalUrl,
            decodeNavigationTitle(response.headers.get("X-Nitro-Title")),
          );
          if (meta.shouldCache) {
            touchNavCache(finalUrl, snapshot);
            if (finalUrl !== meta.url) {
              touchNavCache(meta.url, snapshot);
            }
          }
          return snapshot;
        });
      })
      .finally(function () {
        inflightNavigations.delete(meta.url);
      });

    inflightNavigations.set(meta.url, request);
    return request;
  }

  function updateCurrentHistoryState() {
    var state = history.state || {};
    var defaults = getDefaultNavigationContext();
    history.replaceState(
      {
        nitroNavigate: true,
        url: normalizeUrl(window.location.href),
        scrollX: window.scrollX,
        scrollY: window.scrollY,
        targetSelector: state.targetSelector || defaults.targetSelector,
        selectSelector: state.selectSelector || defaults.selectSelector,
        oobSelectors: state.oobSelectors || defaults.oobSelectors,
        shouldCache:
          typeof state.shouldCache === "boolean"
            ? state.shouldCache
            : defaults.shouldCache,
        shouldPrefetch:
          typeof state.shouldPrefetch === "boolean"
            ? state.shouldPrefetch
            : defaults.shouldPrefetch,
      },
      "",
      window.location.href,
    );
  }

  function seedCurrentPage(meta) {
    var snapshot = currentPageSnapshot(meta);
    if (!snapshot) return;

    if (meta.shouldCache) {
      touchNavCache(snapshot.url, snapshot);
    }

    history.replaceState(
      {
        nitroNavigate: true,
        url: normalizeUrl(window.location.href),
        scrollX: window.scrollX,
        scrollY: window.scrollY,
        targetSelector: meta.targetSelector,
        selectSelector: meta.selectSelector,
        oobSelectors: meta.oobSelectors,
        shouldCache: meta.shouldCache,
        shouldPrefetch: meta.shouldPrefetch,
      },
      "",
      window.location.href,
    );
  }

  function navigateWithSnapshot(meta, options) {
    options = options || {};

    var cached = meta.shouldCache ? navCache.get(meta.url) : null;
    var pushUrl = meta.url;
    var progressToken = { url: meta.url, startedAt: Date.now() };

    if (!options.popstate) {
      updateCurrentHistoryState();
    }

    if (cached) {
      pushUrl = cached.url || meta.url;

      if (meta.shouldPush && !options.popstate) {
        history.pushState(
          {
            nitroNavigate: true,
            url: pushUrl,
            scrollX: 0,
            scrollY: 0,
            targetSelector: meta.targetSelector,
            selectSelector: meta.selectSelector,
            oobSelectors: meta.oobSelectors,
            shouldCache: meta.shouldCache,
            shouldPrefetch: meta.shouldPrefetch,
          },
          "",
          pushUrl,
        );
      }

      applySnapshot(cached, { cached: true, popstate: options.popstate });

      if (!options.popstate) {
        window.scrollTo(0, 0);
      } else if (options.restoreScroll) {
        window.scrollTo(options.restoreScroll.x || 0, options.restoreScroll.y || 0);
      }

      fetchSnapshot(meta)
        .then(function (fresh) {
          if (normalizeUrl(window.location.href) !== normalizeUrl(pushUrl)) return;
          applySnapshot(fresh, { cached: true, revalidated: true, popstate: options.popstate });
        })
        .catch(function (error) {
          console.warn(error);
        });

      return;
    }

    startNavigationProgress(progressToken);

    // IMPORTANT: do NOT touch history before the response is parsed. A link can
    // point at a page on a DIFFERENT layout that has no nav root (e.g. the guest
    // login/register pages) — such a destination isn't SPA-navigable, so
    // parseSnapshotFromDocument() rejects and we fall back to a full navigation.
    // Pushing optimistically here (as this used to) left a broken duplicate
    // history entry in that case: the URL bar showed the destination while the
    // page fell back to a full load, so Back had nowhere to go ("Back doesn't
    // change the page"). We only push once we KNOW the snapshot is applicable.
    fetchSnapshot(meta)
      .then(function (snapshot) {
        if (meta.shouldPush && !options.popstate) {
          history.pushState(
            {
              nitroNavigate: true,
              url: snapshot.url,
              scrollX: 0,
              scrollY: 0,
              targetSelector: meta.targetSelector,
              selectSelector: meta.selectSelector,
              oobSelectors: meta.oobSelectors,
              shouldCache: meta.shouldCache,
              shouldPrefetch: meta.shouldPrefetch,
            },
            "",
            snapshot.url,
          );
        }

        applySnapshot(snapshot, { cached: false, popstate: options.popstate });

        if (!options.popstate) {
          window.scrollTo(0, 0);
        } else if (options.restoreScroll) {
          window.scrollTo(options.restoreScroll.x || 0, options.restoreScroll.y || 0);
        }
      })
      .catch(function (error) {
        // Destination isn't SPA-navigable (no nav root) or the fetch failed.
        // Fall back to a real navigation. Because history was never touched
        // above, the entry is clean and Back behaves normally afterwards.
        console.warn(error);
        window.location.assign(meta.url);
      })
      .finally(function () {
        finishNavigationProgress(progressToken);
      });
  }

  function prefetchNavigation(el) {
    if (!shouldHandleNavigation(el)) return;

    var meta = resolveNavigationMeta(el);
    if (
      !meta.shouldCache ||
      !meta.shouldPrefetch ||
      navCache.has(meta.url) ||
      inflightNavigations.has(meta.url)
    ) {
      return;
    }

    fetchSnapshot(meta).catch(function () {});
  }

  document.addEventListener(
    "click",
    function (e) {
      var el = closestElement(e.target, "[data-nitro-nav='true'], [hx-navigate]");
      if (!el || !isPrimaryClick(e) || !shouldHandleNavigation(el)) return;

      var meta = resolveNavigationMeta(el);
      if (meta.url === normalizeUrl(window.location.href)) {
        e.preventDefault();
        return;
      }

      e.preventDefault();
      el.__nitroNavHandled = true;

      navigateWithSnapshot(meta);
    },
    true,
  );

  document.body.addEventListener("htmx:beforeRequest", function (e) {
    var el = e.detail && e.detail.elt;
    if (!el || !el.__nitroNavHandled) return;

    el.__nitroNavHandled = false;
    e.preventDefault();
  });

  document.addEventListener(
    "mouseenter",
    function (e) {
      var el = closestElement(e.target, "[data-nitro-nav='true'], [hx-navigate]");
      if (el) prefetchNavigation(el);
    },
    true,
  );

  document.addEventListener(
    "focusin",
    function (e) {
      var el = closestElement(e.target, "[data-nitro-nav='true'], [hx-navigate]");
      if (el) prefetchNavigation(el);
    },
    true,
  );

  window.addEventListener("popstate", function (e) {
    var state = e.state || {};
    if (!state.nitroNavigate) {
      // This history entry was never tagged by Nitro (e.g. the initial full
      // page load whose snapshot couldn't be seeded, or navigating back past
      // SPA-managed history). The browser has already changed the URL, so a
      // full reload brings the page content back in sync — otherwise the
      // previous page's DOM stays under the new URL (URL changes, content
      // doesn't). location.href is already the target entry's URL here.
      window.location.reload();
      return;
    }
    var defaults = getDefaultNavigationContext();

    var meta = {
      url: normalizeUrl(window.location.href),
      targetSelector: state.targetSelector || defaults.targetSelector,
      selectSelector: state.selectSelector || defaults.selectSelector,
      oobSelectors: state.oobSelectors || defaults.oobSelectors,
      shouldPush: false,
      shouldCache:
        typeof state.shouldCache === "boolean"
          ? state.shouldCache
          : defaults.shouldCache,
      shouldPrefetch: false,
    };

    navigateWithSnapshot(meta, {
      popstate: true,
      restoreScroll: {
        x: state.scrollX || 0,
        y: state.scrollY || 0,
      },
    });
  });

  // ═══════════════════════════════════════════════════════════════════════
  // SECTION 7: BOOT
  //
  // Compile on DOMContentLoaded, then re-compile after every HTMX swap
  // so dynamically inserted components are picked up automatically.
  // ═══════════════════════════════════════════════════════════════════════

  function boot() {
    compile(document.body);
    seedCurrentPage(getDefaultNavigationContext());
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }

  // Re-compile after every swap + auto-focus
  document.body.addEventListener("htmx:afterSettle", function (e) {
    var target = e.detail.target || e.target;
    if (!document.contains(target)) {
      target = e.detail.elt
        ? e.detail.elt.parentElement || document.body
        : document.body;
    }
    compile(target);

    // hx-focus: auto-focus after swap
    var focusEl = target.querySelector("[hx-focus]");
    if (focusEl) focusEl.focus();
  });

  // ── Public API ──

  window.HxComponent = window.HxComponent || {};
  window.HxComponent.execute = execute;
  window.HxComponent.parseAction = parseAction;
  window.HxComponent.flushDeferred = flushDeferred;
  window.HxComponent.compile = compile;
})();
