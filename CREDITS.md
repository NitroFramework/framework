# Credits & Acknowledgements

NitroPHP is an independent, from-scratch PHP framework. Parts of its
developer-facing API deliberately mirror the **naming and ergonomics** of the
projects listed below so that code written for them feels familiar and stays
portable.

NitroPHP is **not affiliated with, endorsed by, or sponsored by** any of these
projects. Their names, logos, and trademarks remain the property of their
respective owners, and are referenced here only to describe compatibility and
inspiration.

- **Laravel** — an open-source PHP framework (MIT License) created by
  Taylor Otwell and its contributors. <https://laravel.com>
  NitroPHP mirrors Laravel's public API shape, naming, and conventions, but is
  an independent reimplementation — **not a fork of, or derived from, Laravel's
  source code.** A read-only copy of Laravel is used privately during
  development only for API-shape comparison and is not distributed.

- **Livewire** — an open-source full-stack framework for Laravel (MIT License)
  created by Caleb Porzio and its contributors. <https://livewire.laravel.com>
  NitroPHP's `Nitro\Livewire` layer is an **independent implementation inspired
  by** Livewire's ideas, attribute names, and lifecycle conventions. It is not
  Livewire and does not include Livewire's source.

- **htmx** — an open-source hypermedia library created by Big Sky Software.
  <https://htmx.org>
  NitroPHP's HTMX layer draws on htmx's `hx-*` attribute conventions and
  hypermedia approach. It is an independent implementation and does not bundle
  htmx itself.

- **Viewi** — an open-source PHP front-end framework (MIT License) created by
  Ivan Voitovych and its contributors. <https://viewi.net>
  Unlike the projects above, NitroPHP's `Nitro\Fusion` layer **includes adapted
  source** from Viewi: its PHP→JavaScript transpiler (`JsTranspile`) and its
  PHP-standard-library-in-JavaScript runtime (`PhpJsFunctions`) are vendored
  under the `Nitro\Fusion\*` namespace (see `src/Fusion/JsTranspile` and
  `src/Fusion/PhpJsFunctions`). Copyright © Ivan Voitovych, used under the MIT
  License. The surrounding Fusion component model, reactive-Blade compiler, SSR
  bridge, and runtime are original NitroPHP code.

- **Locutus** (php.js) — MIT-licensed JavaScript reimplementations of PHP's
  standard library. <https://locutus.io>
  The `.js` files under `src/Fusion/PhpJsFunctions` derive from Locutus (via
  Viewi) and remain under their MIT License.

All original NitroPHP source code is released under the MIT License — see
[LICENSE](LICENSE).
