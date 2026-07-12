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

> The Fusion client-side layer (transpiler, `PhpJsFunctions`/Locutus shim, Viewi
> attribution) now lives in its own package, **`nitro/fusion`** — see that
> repository's `CREDITS.md`.

All original NitroPHP source code is released under the MIT License — see
[LICENSE](LICENSE).
