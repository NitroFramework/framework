# Fusion — Nitro Client Components — Design

> **Name:** Fusion (isomorphic = *fusing* server + client execution from one
> PHP source). Namespace `Nitro\Fusion`. — decided.

**Status:** **P0 spike VALIDATED** ✅ — the core bet works end to end. Opt-in third
reactive layer alongside Livewire & HTMX. Livewire (server-authoritative
round-trips) and HTMX (hypermedia) are **not** touched.

## P0 spike results (validated)

Proved the whole pipeline on a `Counter` (spike at `.reference/viewi/spike/`):

- **Transpiler works with ZERO adaptation.** Viewi's `JsTranspiler` turns a plain
  Livewire-style component into a clean JS class:
  `public int $count = 0` → `count = 0`; `increment(){ $this->count++ }` →
  `increment(){ $this.count++ }`. Confirmed running correctly in a JS engine
  (increment/decrement/reset/double).
- **Blade → reactive JS.** A minimal compiler turned `{{ $count }}` + `fusion:click`
  into a JS render fn + delegated `data-fusion-click` bindings.
- **SSR hydrate + reactive updates.** Mounted with server state `count=5`,
  `fusion:click="increment"` ran client-side and re-rendered `5→6→7→6` with **no
  server round-trip**.

**Findings that shape P1:**
1. The raw PHP→JS **class** transpile is clean and directly reusable.
2. Viewi's **fine-grained dependency graph** is coupled into its
   `TemplateCompiler`/`Builder` — NOT trivially reusable. **Decision:** P1 uses
   **coarse reactivity** — re-render the component in JS and **patch the DOM with
   idiomorph** (already a Nitro dep, used by the HTMX/Livewire layers). This is
   essentially "Livewire's morph, but the render runs in the browser." Svelte-style
   fine-grained dep-tracking becomes a later optimization (P3+), not a blocker.
3. The real reactive-Blade compiler must transpile `{{ }}` **expressions** through
   `JsTranspiler` (for `{{ $count * 2 }}`, `{{ $this->double() }}`) with the
   component's prop list as context — the P0 regex is a placeholder.

## 0. Layering — a separate layer ON TOP of Livewire (decided)

Fusion is its **own layer** that **depends on** Livewire, not part of it:

- **Reuse by composition, not modification.** Fusion components `extend
  Livewire\Component` and reuse `SynthManager`, `Checksum`, `SecurityPolicy` at
  the `#[Server]` boundary — so Livewire stays untouched and authoring feels
  identical to Livewire.
- **Keep Livewire lean.** The transpiler, the PHP-builtins-in-JS shim, the
  reactive-Blade compiler, the build pipeline, and the client runtime have no
  place in Livewire — server-only Livewire users must not pay for a transpiler.
  Fusion carries all of it, behind its own service provider, cleanly excludable.
- If any reuse needs a seam in Livewire (e.g. a `private` → `protected` method),
  it is called out explicitly — never a silent change to Livewire's behavior.

## 1. The concept — "client-side Livewire"

Write a component the way you already write Livewire — a component class
(`public` props = state, methods = handlers) + a Blade view with `fusion:` bindings
(Livewire-shaped, but under Fusion's own namespace — never `wire:`).
The **only** difference is execution: the component's logic is **transpiled to
JavaScript at build time and runs in the browser** (instant, no round-trip,
offline-capable), while Nitro's Blade renders it **server-side for first paint /
SEO**. Same authoring, isomorphic execution.

> Borrowed from Viewi: only the **PHP→JS expression/method transpiler** and its
> **PHP-builtins-in-JS runtime shim** (MIT). We reject Viewi's template syntax
> and its DI/Router/Engine — those become Nitro's.

## 2. Authoring model (decided)

```php
// app/Components/Counter.php
#[Client]                                     // opt in: transpile to client
class Counter extends Component
{
    use Transpilable;                         // client-bridge machinery

    public int $count = 0;                    // reactive state (public props)

    public function increment(): void         // pure UI → transpiled, runs in browser
    {
        $this->count++;
    }

    #[Server]                                 // NOT transpiled → auto-generated,
    public function persist(): void           // authenticated, validated endpoint
    {
        Setting::put('count', $this->count);  // real Nitro ORM, server-side
    }
}
```

```blade
{{-- resources/views/components/counter.blade.php --}}
<div>
  <button fusion:click="increment">+</button>
  <span>{{ $count }}</span>                     {{-- reactive: re-renders in JS --}}
  <button fusion:click="persist">save</button>  {{-- awaits the server endpoint --}}
</div>
```

**Decisions locked:**
- **Opt-in:** `#[Client]` attribute on a normal `Component` (build marker) +
  `use Transpilable` (runtime plumbing). One component model.
- **Method split:** unmarked methods **transpile** (client); `#[Server]` methods
  stay server-only and the client gets an auto-generated endpoint stub.
- **Template:** Blade — a **reactive subset** (see §6). `fusion:click` /
  `fusion:model` — Livewire-shaped conventions under Fusion's **own** `fusion:`
  namespace, kept distinct from the Livewire layer's `wire:` (each layer owns
  its directives; a Fusion-only app never sees `wire:`).
- **Data boundary:** `#[Server]` RPC sugar (auth'd + validated), reusing
  Livewire's `Checksum` + `SecurityPolicy`. Explicit injected API client also
  available for hand-rolled calls.

## 3. What runs where — Pure UI methods vs Data methods

The spine of the whole design is this split:

- **Pure UI methods** (unmarked) — state changes, computed values, event
  handlers, filtering, toggles. **Transpiled to JS, run in the browser.** No
  server, no round-trip. May only touch component state + other pure methods.
- **Data methods** (`#[Server]`) — anything that reads/writes the DB, needs Auth,
  secrets, or sensitive logic. **Never transpiled**; the client calls an
  auto-generated, authenticated, validated endpoint and applies the returned
  state patch.

| Transpiled → browser (Pure UI) | Server-only (Data) |
|---|---|
| `public` props (reactive state) | DB / ORM, Auth, sessions, secrets, mail, queue |
| unmarked methods (UI logic, computed, handlers) | `#[Server]` methods (via generated endpoint) |
| the reactive Blade subset | SSR initial render + initial-state serialization |
| pure helper functions | anything touching Container-bound services |

**Hard rule the build enforces:** a transpiled method may not reference
Container services / models / Auth. A build-time lint fails the build if a
`#[Client]`-transpiled path reaches server-only surface — so business logic can't
accidentally ship to the client.

## 4. Pipeline

```
Counter.php + counter.blade.php
        │  nitro fusion:build   (also hooked into `nitro optimize`)
        ├─ Transpiler (adapted Viewi JsTranspile: nikic/php-parser AST → JS)  → Counter.mjs
        ├─ Reactive-Blade compiler (Nitro Blade parse → JS render + dep-paths) → view render (JS)
        ├─ Nitro Blade (unchanged)                                            → SSR render (PHP)
        └─ Manifest (props, methods, #[Server] map, events, dep-paths, checksum key refs)
   ┌────┴─────────────────────────────────┐
   Server: Nitro renders SSR HTML +         Client: runtime hydrates, reactive updates in JS;
   serialized signed initial state + bundle  #[Server] calls → signed POST → Nitro endpoint
```

## 5. The `Transpilable` trait (the user's idea, fleshed out)

A capability trait `use`d by every `#[Client]` component. Dual-nature — some of
its surface is itself transpiled (client bridge), some runs on the server (SSR /
endpoint handling):

- **Hydration (server):** `toClientState()` serializes public props into the page
  for the client to boot from — reuse Livewire's `SynthManager` (models→arrays,
  enums, collections) so non-scalar state round-trips.
- **`#[Server]` bridge (client side, transpiled):** each `#[Server]` method
  becomes a client stub that posts `{ component, method, state, checksum }` to the
  generated endpoint and applies the returned state patch.
- **`#[Server]` handler (server):** verifies the snapshot with `Checksum`, runs
  the real method through Nitro's Container (DI, Auth, Validation), returns a
  signed state patch. `SecurityPolicy` gates any class instantiation. This is
  exactly the Livewire threat model — reused, not reinvented — but it now applies
  ONLY at the `#[Server]` boundary.
- **Lifecycle:** `mounted()` (client) vs a server data-hook for SSR.

`#[Client]` (metadata for the build) + `Transpilable` (behavior) can be collapsed
into one if we prefer — kept separate so the marker stays cheap to scan and the
base `Component` stays lean. *(open — see §9)*

## 6. Reactive Blade subset

Reuse Nitro's Blade compiler for parsing + SSR. For the client, a companion pass
walks the parsed template, transpiles each dynamic expression to JS, and computes
its dependency paths for fine-grained updates.

- **In (reactive):** `{{ $expr }}`, `{!! !!}` (trusted), `@if/@elseif/@else`,
  `@foreach/@for`, `@class/@style`, `fusion:click|submit|…`, `fusion:model[.lazy]`,
  `<x-child :prop="$x" />`, `fusion:key`.
- **Out (won't transpile — documented, build warns):** arbitrary `@php` blocks,
  side-effecty echoes, directives with no client meaning, raw server helpers in
  expressions.
- **Reactivity:** compile-time dependency paths (Svelte/Viewi-style) — when a prop
  changes, only the DOM regions whose paths include it update. No VDOM.

## 7. Reuse of Nitro internals (the "adapt to internals" part)

- **Container** — SSR resolves the component + its server deps; the build extracts
  ctor deps and separates client-safe from server-only.
- **Blade / View** — unchanged for SSR; a Blade layout is the document shell
  (SPA root + serialized state + bundle `<script>`).
- **Router** — Nitro serves the shell route + SSR; we emit a JS route table from
  Nitro's **named routes** so `route()` works client-side; nav interops with the
  existing HTMX/Livewire history handling.
- **Validation** — server rules stay Nitro (run in `#[Server]` handlers); simple
  rules optionally mirrored to the client for instant feedback.
- **Livewire pieces** — `SynthManager` (state serialization), `Checksum`,
  `SecurityPolicy` reused at the `#[Server]` boundary.
- **Assets** — bundle served via a framework JS route; must not be shadowed by the
  Caddyfile static rule under worker mode (see prior fix).
- **Build** — `nitro fusion:build`; folded into `nitro optimize`; dev-mode
  watch/on-the-fly transpile.

## 8. Layer structure (`src/Fusion/` — name TBD)

`Attributes/` (`Client`, `Server`, `Prop`) · `Concerns/Transpilable.php` ·
`Compiler/` (transpiler adapter + reactive-Blade compiler) · `Runtime/` (SSR
bridge to Nitro) · `Build/` (build command, manifest, bundler, client-purity
lint) · `Router/` (client route-table generator) · `Http/` (client/SSR data
client + `#[Server]` endpoint) · `js/` (reactive runtime + PhpJsFunctions shim) ·
`FusionServiceProvider.php`.

## 9. Decided vs open

**Decided:** name **Fusion** (`Nitro\Fusion`) · separate layer atop Livewire ·
`#[Client]` + `Transpilable` opt-in · pure-UI vs `#[Server]` data-method split ·
Blade (reactive subset) + `fusion:` directives (own namespace) · SSR via Nitro Blade.

**Still open:**
1. **Transpiler sourcing.** Adapt/vendor Viewi's `JsTranspile` + `PhpJsFunctions`
   (recommended, fastest, inherits its PHP-subset limits) vs build our own on
   nikic/php-parser. *Leaning: adapt.*
2. **`#[Client]` + trait** kept separate, or collapse into just the trait (`use
   Transpilable` implies client) or just the attribute. *Leaning: keep separate.*
3. **Exact reactive-Blade-subset spec** — enumerate supported directives/expr
   shapes precisely (drives the compiler + the "what you may write" docs).

## 10. Phased roadmap
- **P0 — Spike:** ✅ **DONE** — transpiled `Counter` PHP→JS, compiled Blade→JS,
  SSR-hydrated, `fusion:click` reactive client-side. Everything below is now
  de-risked. (`.reference/viewi/spike/`)
- **P1 — MVP** (in progress):
  - ✅ **Transpiler vendored.** `Viewi\JsTranspile` + `PhpJsFunctions` copied into
    `src/Fusion/{JsTranspile,PhpJsFunctions}`, namespace rewritten `Viewi\` →
    `Nitro\Fusion\` (335 php + 328 js). Added `nikic/php-parser: ^5.3`. Fixed 3
    reserved-word class landmines (`Echo`/`Empty`/`Isset` → `_Echo`/`_Empty`/
    `_Isset`). All lint clean, PSR-4 valid, autoload optimized, registry loads
    (325 fns), transpile verified under Nitro autoload. Attribution added to
    `CREDITS.md` (Viewi MIT + Locutus MIT). Framework suite still green (1516).
  - ✅ **`Attributes/Client` + `Attributes/Server`** landed.
  - ✅ **`Transpiler/ComponentTranspiler`** — the Nitro adaptation layer over the
    vendored engine (engine untouched). `#[Server]` methods → RPC stub
    (`$this->__fusionCall('name', [...args])`), so server logic never reaches the
    client; Pure-UI methods transpiled; public props collected as reactive state;
    **client-purity enforced** (`new X` / `Model::create()` in a Pure-UI method →
    reported violation). 13 Fusion tests green; full suite 1529.
  - ✅ **`Concerns/Transpilable`** — `fusionState()` serializes public props for
    SSR hydration (via Livewire `SynthManager`); `fusionFill()` rebuilds them
    from client state, **public props only** (protected/private never writable).
  - ✅ **Reactive-Blade compiler (`Compiler/BladeCompiler`)** — `{{ $expr }}`
    (transpiled, HTML-escaped) + `{!! !!}` (raw) + `fusion:click|submit|…` →
    delegated events + `fusion:model` → bound props → a JS render fn that
    destructures public props & aliases `$this`. (`@if`/`@foreach` next.)
  - ✅ **Runtime (`js/fusion.js`)** — Fusion's own runtime (separate from
    Livewire's): mounts `[data-fusion-root]`, hydrates SSR state, delegated
    `data-fusion-*` events, runs Pure-UI methods in-browser + re-renders, `__fusionCall`
    bridge for `#[Server]`. v1 re-render is coarse innerHTML (delegated listeners
    survive it); idiomorph patch is the planned upgrade.
  - ✅ **`Build/Builder` + `nitro fusion:build`** — transpiles `#[Client]`
    components + compiles views → a self-registering browser bundle; **purity
    enforced** (impure component fails the build). Registered in CommandManager.
  - ✅ **E2E proven (jsdom):** SSR `count=5` → hydrate → `fusion:click="increment"`
    ran client-side → reactive DOM update `5→6→7→6`, no round-trips. Real bundle
    (Builder) + real runtime (fusion.js). 26 Fusion tests; full suite 1542.
  - ✅ **SSR (`Runtime/FusionRenderer`) + `@fusion` / `@fusionScripts` directives**
    — server-renders a `#[Client]` component (DI + mount) to a `[data-fusion-root]`
    with serialized hydration state, in the SAME `data-fusion-*` shape the client
    render emits (no hydration flash). Transpiler hardened: **protected/private
    props and `use Transpilable` are stripped from the client JS** (server state
    never ships).
  - ✅ **`#[Server]` endpoint (`Runtime/FusionServer`) + `POST /nitro/fusion/call`**
    — CSRF-verified; rebuilds the component from client state (public props only),
    runs ONLY a declared `#[Server]` method (rejects pure/internal/arbitrary),
    returns the state patch. Wired via `FusionServiceProvider` (in default providers).
  - ✅ **Full loop proven E2E (jsdom):** PHP component → SSR (FusionRenderer) →
    hydrate → `fusion:click` transpiled `increment()` ran in-browser → reactive
    `5→6→7`. 33 Fusion tests; full suite **1549**.
  - ⬜ Live browser demo under Thrust (playground) + idiomorph patch + `@if`/`@foreach`.

**P1 essentially complete** — author a Livewire-style component + Blade view →
`nitro fusion:build` → it runs as a reactive SPA with Nitro SSR. Remaining is a
live worker-mode browser demo and the reactivity/subset polish (P1 tail → P2).
- **P2 — Data:** `#[Server]` sugar (auth'd/validated, signed), forms, injected
  API client.
- **P3 — Routing:** client router from named routes, SSR route matching, lazy/split.
- **P4 — Interop & polish:** coexist with HTMX/Livewire on a page, dev watch,
  source maps, error surfaces, optimize integration.

## 11. Biggest risks
- Transpiler coverage (inherited PHP subset + its bugs) → publish a strict
  "component PHP you may use" contract.
- SSR↔client DOM parity for clean hydration.
- The client-purity boundary must be structurally enforced (build lint), not
  convention.
- Worker-mode behavior of SSR render + bundle serving under Thrust.
- The PHP-funcs-in-JS shim is a permanent maintenance surface even borrowed.
