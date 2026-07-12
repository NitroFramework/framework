/**
 * Fusion runtime — the browser half of Nitro's client-side reactive layer.
 *
 * A `nitro fusion:build` bundle self-registers each component into
 * window.__fusion.registry as { component: <transpiled class>, render: <fn>, meta }.
 * This runtime mounts every [data-fusion-root] in the page: it hydrates the
 * component from the SSR-embedded state, runs Pure-UI methods IN THE BROWSER
 * (no round-trip) and re-renders reactively, and defers #[Server] methods to an
 * authenticated endpoint.
 *
 * This is Fusion's own runtime — deliberately separate from the Livewire and
 * HTMX runtimes, which have a different (server-authoritative) execution model.
 */
(function () {
  'use strict';

  var Fusion = (window.__fusion = window.__fusion || { registry: {} });
  Fusion.instances = [];

  // HTML escape used by compiled render functions ({{ }} -> ${__esc(expr)}).
  function esc(v) {
    if (v === null || v === undefined) return '';
    return String(v)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }
  window.__esc = esc;

  var DELEGATED = ['click', 'submit', 'change', 'input', 'keydown', 'keyup', 'blur', 'focus'];

  function mount(root) {
    var name = root.getAttribute('data-fusion-name');
    var def = Fusion.registry[name];
    if (!def) {
      console.warn('[fusion] no component registered for', name);
      return;
    }

    var state = {};
    try {
      state = JSON.parse(root.getAttribute('data-fusion-state') || '{}');
    } catch (e) {
      /* keep defaults */
    }

    var instance = new def.component();
    Object.assign(instance, state); // hydrate from SSR-serialized public props

    var app = { name: name, root: root, def: def, instance: instance };
    instance.__fusionCall = function (method, args) {
      return serverCall(app, method, args);
    };

    bindEvents(app);
    Fusion.instances.push(app);
    return app; // SSR already painted the initial HTML — no first render needed
  }

  function render(app) {
    // v1 coarse reactivity: re-render the whole component. Delegated listeners
    // live on the root, so they survive the innerHTML swap. (idiomorph-based
    // patching that preserves focus/selection is the planned upgrade.)
    var active = document.activeElement;
    var activeModel = active && active.getAttribute ? active.getAttribute('data-fusion-model') : null;
    var caret = active && typeof active.selectionStart === 'number' ? active.selectionStart : null;

    app.root.innerHTML = app.def.render(app.instance);

    // The template binds wire:model, not value, and innerHTML rebuilds inputs —
    // so reflect model state back onto each input and restore focus/caret to the
    // one being edited. Without this, typing resets after a single character.
    app.root.querySelectorAll('[data-fusion-model]').forEach(function (el) {
      var prop = el.getAttribute('data-fusion-model');
      var val = app.instance[prop];
      val = val === null || val === undefined ? '' : String(val);
      if (el.value !== val) {
        el.value = val;
      }
      if (prop === activeModel) {
        el.focus();
        if (caret !== null && el.setSelectionRange) {
          try {
            el.setSelectionRange(caret, caret);
          } catch (e) {
            /* non-text input (e.g. color) */
          }
        }
      }
    });
  }

  function bindEvents(app) {
    DELEGATED.forEach(function (evt) {
      app.root.addEventListener(evt, function (e) {
        // wire:model two-way binding
        if (evt === 'input' || evt === 'change') {
          var bound = e.target.closest('[data-fusion-model]');
          if (bound && app.root.contains(bound)) {
            app.instance[bound.getAttribute('data-fusion-model')] = bound.value;
            render(app);
            return;
          }
        }
        // wire:<event>="method"
        var target = e.target.closest('[data-fusion-' + evt + ']');
        if (target && app.root.contains(target)) {
          if (evt === 'submit') e.preventDefault();
          var method = target.getAttribute('data-fusion-' + evt);
          var fn = app.instance[method];
          if (typeof fn === 'function') {
            fn.call(app.instance);
            render(app);
          }
        }
      });
    });
  }

  // #[Server] method → authenticated POST; apply the returned state patch.
  function serverCall(app, method, args) {
    return fetch(Fusion.callUri || '/nitro/fusion/call', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': Fusion.csrf || '',
      },
      body: JSON.stringify({
        component: app.name,
        method: method,
        args: args || [],
        state: publicState(app.instance),
      }),
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (patch) {
        if (patch && patch.state) {
          Object.assign(app.instance, patch.state);
          render(app);
        }
        return patch;
      });
  }

  function publicState(instance) {
    var out = {};
    Object.keys(instance).forEach(function (k) {
      if (k.indexOf('__') !== 0 && typeof instance[k] !== 'function') {
        out[k] = instance[k];
      }
    });
    return out;
  }

  // data-fusion-current — active-link highlighting for Fusion routes, the Fusion
  // layer's counterpart to wire:current / hx-current. Fusion routes are full-page
  // loads, so (unlike the SPA layers) this only needs to run on load: each
  // [data-fusion-current] link is matched against the current URL, toggling its
  // classes and setting a bare data-current attribute you can style in CSS.
  // Matching is on the link's href. Modifiers: .exact, .strict, .ignore.
  // Self-contained in the Fusion runtime — no dependency on Livewire or htmx.
  function pathMatches(hrefUrl, actualUrl, options) {
    if (hrefUrl.hostname !== actualUrl.hostname) return false;
    var hrefPath = options.strict ? hrefUrl.pathname : hrefUrl.pathname.replace(/\/+$/, '');
    var actualPath = options.strict ? actualUrl.pathname : actualUrl.pathname.replace(/\/+$/, '');
    if (options.exact) return hrefPath === actualPath;
    var h = hrefPath.split('/'), a = actualPath.split('/');
    for (var i = 0; i < h.length; i++) {
      if (h[i] !== a[i]) return false;
    }
    return true;
  }

  function fusionCurrentAttr(el) {
    for (var i = 0; i < el.attributes.length; i++) {
      if (el.attributes[i].name.indexOf('data-fusion-current') === 0) return el.attributes[i];
    }
    return null;
  }

  function refreshCurrentLinks() {
    var url = new URL(window.location.href);

    document.querySelectorAll('a[href]').forEach(function (el) {
      var attr = fusionCurrentAttr(el);
      if (!attr) return;

      var href = el.getAttribute('href');
      if (!href || href.charAt(0) === '#') return;

      var hrefUrl;
      try {
        hrefUrl = new URL(href, window.location.href);
      } catch (e) {
        return;
      }

      var m = attr.name === 'data-fusion-current'
        ? []
        : attr.name.slice('data-fusion-current'.length + 1).split('.');
      if (m.indexOf('ignore') !== -1) return;

      var options = { exact: m.indexOf('exact') !== -1, strict: m.indexOf('strict') !== -1 };
      var isCurrent = pathMatches(hrefUrl, url, options);
      var classes = (attr.value || '').split(' ').filter(Boolean);

      if (isCurrent) {
        if (classes.length) el.classList.add.apply(el.classList, classes);
        el.setAttribute('data-current', '');
      } else {
        if (classes.length) el.classList.remove.apply(el.classList, classes);
        el.removeAttribute('data-current');
      }
    });
  }

  function boot() {
    document.querySelectorAll('[data-fusion-root]').forEach(mount);
    refreshCurrentLinks();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  Fusion.mount = mount;
  Fusion.boot = boot;
  Fusion.render = render;
})();
