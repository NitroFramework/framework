/**
 * Fusion navigation progress — the top bar for Route::fusion() page loads.
 *
 * A Fusion route is a full-page navigation (a plain <a>, so the client runtime
 * mounts on arrival), so unlike Livewire's wire:navigate SPA hop there are no
 * navigating/navigated events to hook. So:
 *   - START on click of a fusion link (the bar rises immediately), and
 *   - because the full reload would otherwise discard the bar, we bridge across
 *     the reload with sessionStorage: the destination document resumes the bar
 *     and completes it (DONE) once the page has loaded.
 *
 * Visual settings come from window.NitroNProgress.fusion (config/nprogress.php),
 * the same object that drives the htmx/livewire bars. This script loads GLOBALLY
 * (it must catch link clicks on any page), separately from the Fusion runtime
 * (fusion.js), which only loads on Fusion pages.
 */
(function () {
  if (window.__fusionNavProgressWired) return;

  var NP = window.NProgress;
  var root = window.NitroNProgress || {};
  var cfg = root.fusion || {};
  if (!NP || root.enabled === false || cfg.enabled === false) return;

  window.__fusionNavProgressWired = true;

  var visual = Object.assign({}, root.visual || {}, cfg);
  var selector = cfg.selector || '[data-fusion-current]';
  var KEY = '__fusionNav';

  function start() {
    var opts = {};
    ['speed', 'minimum', 'trickle', 'trickleSpeed', 'easing', 'showSpinner'].forEach(function (k) {
      if (visual[k] !== undefined) opts[k] = visual[k];
    });
    NP.configure(opts);
    NP.start();
    var bar = document.querySelector('#nprogress .bar');
    if (bar) {
      if (visual.color) bar.style.background = visual.color;
      if (visual.height) bar.style.height = visual.height;
    }
  }

  // START: a plain left-click on a fusion link. Flag the impending reload so the
  // destination document knows to resume and finish the bar.
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var link = e.target.closest ? e.target.closest(selector) : null;
    if (!link) return;
    try { sessionStorage.setItem(KEY, '1'); } catch (_) {}
    start();
  });

  // DONE: on arrival, if we were mid-navigation, resume the bar in the fresh
  // document and complete it once the page has finished loading.
  var navigating = false;
  try {
    navigating = sessionStorage.getItem(KEY) === '1';
    sessionStorage.removeItem(KEY);
  } catch (_) {}

  if (navigating) {
    start();
    if (document.readyState === 'complete') {
      NP.done();
    } else {
      window.addEventListener('load', function () { NP.done(); });
    }
  }
})();
