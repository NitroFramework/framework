/**
 * Nitro NProgress integration.
 *
 * One top progress bar shared by HTMX navigations and Livewire wire:navigate,
 * driven entirely off the framework's navigation EVENT SEAM (the framework is
 * unopinionated about the bar — it only dispatches events):
 *
 *   HTMX:      htmx:beforeRequest / htmx:afterRequest (+ error/timeout), and the
 *              SPA-nav events nitro:navigation-start / nitro:navigation-end.
 *   Livewire:  livewire:navigating / livewire:navigated.
 *
 * WHY this is a static asset bound to `document` / `window` (and not an inline
 * <script> in the body): Livewire's wire:navigate does document.body.replaceWith,
 * which destroys any listener bound to document.body and any body-local state.
 * document and window survive that swap, and HTMX events bubble to document, so
 * a single set of listeners keeps working across unlimited SPA navigations
 * between both runtimes. It is loaded with `defer` after the NProgress vendor
 * script, so NProgress is defined by the time this runs — no DOMContentLoaded
 * gate (which would never re-fire after an SPA swap anyway).
 *
 * Config is injected by the layout as `window.NitroNProgress = @json(config('nprogress'))`.
 */
(function () {
  if (window.__nitroNProgressWired) return; // idempotent across re-execution
  var config = window.NitroNProgress;
  if (!config || !config.enabled || !window.NProgress) return;
  window.__nitroNProgressWired = true;

  // Merge shared `visual` defaults with a context's overrides.
  function visualFor(ctx) {
    var v = Object.assign({}, config.visual || {}, ctx || {});
    var opts = {};
    ['speed', 'minimum', 'trickle', 'trickleSpeed', 'easing', 'showSpinner'].forEach(function (k) {
      if (v[k] !== undefined) opts[k] = v[k];
    });
    return { opts: opts, color: v.color, height: v.height };
  }

  // Configure NProgress for this context, then start + apply colour/height
  // (colour/height are CSS, set on the bar element directly).
  function startBar(visual) {
    window.NProgress.configure(visual.opts);
    window.NProgress.start();
    var bar = document.querySelector('#nprogress .bar');
    if (bar) {
      if (visual.color) bar.style.background = visual.color;
      if (visual.height) bar.style.height = visual.height;
    }
  }

  /* ----- HTMX ----- */
  var hx = config.htmx || {};
  if (hx.enabled) {
    var triggers = Array.isArray(hx.triggers) ? hx.triggers : [];
    var minDuration = Number(hx.min_duration_ms) || 0;
    var hxVisual = visualFor(hx);
    var pending = 0;
    var timers = new Map(); // key (xhr or nav token) -> pending-start timer

    function matchesTriggers(elt) {
      if (!elt || !elt.getAttribute) return false;
      return triggers.some(function (rule) {
        var attr = rule && rule.attribute;
        if (!attr || !elt.hasAttribute(attr)) return false;
        return rule.value === null || rule.value === undefined
          ? true
          : elt.getAttribute(attr) === String(rule.value);
      });
    }

    function startSoon(key) {
      if (minDuration <= 0) {
        pending++;
        if (pending === 1) startBar(hxVisual);
        return;
      }
      var t = setTimeout(function () {
        timers.delete(key);
        pending++;
        if (pending === 1) startBar(hxVisual);
      }, minDuration);
      timers.set(key, t);
    }

    function finish(key) {
      if (timers.has(key)) {
        clearTimeout(timers.get(key));
        timers.delete(key);
        return; // bar never started — nothing to finish
      }
      if (pending > 0) pending--;
      if (pending === 0) window.NProgress.done();
    }

    // Bound to `document`: HTMX events bubble up here and document survives the
    // Livewire body swap.
    document.addEventListener('htmx:beforeRequest', function (evt) {
      if (evt.defaultPrevented) return;
      if (!matchesTriggers(evt.detail.elt)) return;
      var xhr = evt.detail.xhr;
      if (!xhr) return;
      xhr.__npTracked = true;
      startSoon(xhr);
    });

    // Framework SPA-nav events (may be served from the nav cache with no xhr).
    document.addEventListener('nitro:navigation-start', function (evt) {
      var token = evt.detail && evt.detail.token;
      if (token) startSoon(token);
    });
    document.addEventListener('nitro:navigation-end', function (evt) {
      var token = evt.detail && evt.detail.token;
      if (token) finish(token);
    });

    var onEnd = function (evt) {
      var xhr = evt.detail && evt.detail.xhr;
      if (xhr && xhr.__npTracked) finish(xhr);
    };
    document.addEventListener('htmx:afterRequest', onEnd);
    document.addEventListener('htmx:responseError', onEnd);
    document.addEventListener('htmx:sendError', onEnd);
    document.addEventListener('htmx:timeout', onEnd);
  }

  /* ----- Livewire wire:navigate ----- */
  var lw = config.livewire || {};
  if (lw.enabled) {
    var lwVisual = visualFor(lw);
    var lwMin = Number(lw.min_duration_ms) || 0;
    var lwTimer = null;

    // Bound to `window`: Livewire dispatches its nav events there, and window
    // outlives the body swap.
    window.addEventListener('livewire:navigating', function () {
      if (lwMin > 0) lwTimer = setTimeout(function () { startBar(lwVisual); }, lwMin);
      else startBar(lwVisual);
    });
    window.addEventListener('livewire:navigated', function () {
      if (lwTimer) { clearTimeout(lwTimer); lwTimer = null; }
      window.NProgress.done();
    });
  }
})();
