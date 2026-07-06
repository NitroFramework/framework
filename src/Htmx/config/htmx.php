<?php

/*
|--------------------------------------------------------------------------
| Nitro HTMX Layer — Default Configuration
|--------------------------------------------------------------------------
|
| This is the framework-shipped default. Publish it into your app with:
|
|   php nitro htmx:publish
|
| That copies this file to config/htmx.php. After publishing, the app
| copy is the one that loads at runtime — edit it freely.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    |
    | The URL prefix for all HTMX component requests.
    |
    |   /hx/counter/increment
    |   ^^^
    |
    */

    'route_prefix' => '/hx',

    /*
    |--------------------------------------------------------------------------
    | URL & Payload Obfuscation
    |--------------------------------------------------------------------------
    |
    | obfuscation: hash component + action names in URLs so the wire format
    |              doesn't reveal class/method names.
    | encryption:  encrypt hx-vals payloads so clients can't tamper with them.
    |
    */

    'obfuscation' => false,
    'encryption'  => false,

    /*
    |--------------------------------------------------------------------------
    | Allowed HTTP Methods
    |--------------------------------------------------------------------------
    */

    'route_methods' => ['get', 'post'],

    /*
    |--------------------------------------------------------------------------
    | Component Namespace
    |--------------------------------------------------------------------------
    |
    | PSR-4 namespace where your HTMX components live.
    |
    */

    'component_namespace' => 'App\\Htmx\\Components\\',

    /*
    |--------------------------------------------------------------------------
    | Default View Path Prefix
    |--------------------------------------------------------------------------
    |
    | When a component does not declare $view explicitly, the framework
    | infers the view from the class name. With prefix "components.htmx."
    | the class Counter maps to "components.htmx.counter", StudentTable to
    | "components.htmx.student-table" (kebab-cased).
    |
    */

    'view_path_prefix' => 'components.htmx.',

    /*
    |--------------------------------------------------------------------------
    | Session Key Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix used when storing component state in the session.  Affects both
    | the legacy remember()/store() API and the new auto-state machinery.
    |
    */

    'session_prefix' => 'htmx_',

    /*
    |--------------------------------------------------------------------------
    | State Store
    |--------------------------------------------------------------------------
    |
    | Where component state lives. Pick a backend:
    |
    |   'session' (default)    PHP's $_SESSION. Per-user automatically.
    |                          No setup. Bound to session lifetime.
    |
    |   'cache'                Delegates to Nitro's Cache layer. Use
    |                          'cache_driver' below to pick the driver
    |                          ('redis', 'file', 'memcached', 'array').
    |                          Per-user isolation handled internally via
    |                          session-id key prefixing.
    |
    |   'array'                In-memory, request-lifetime only. Useful
    |                          for tests; useless for production.
    |
    */

    'state' => [
        'store'        => 'session',
        'cache_driver' => null,   // null = the cache's default driver
        'ttl'          => null,   // seconds; null = no expiry (cache stores only)
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-Render
    |--------------------------------------------------------------------------
    |
    | When true, action methods that do not call render(), value(), or
    | return a Response will automatically render the component's default
    | view with its public properties as data — Livewire-style.
    |
    | Components can override this individually via:
    |     protected bool $autoRender = false;
    |
    | And per-action via $this->skipRender() inside the method.
    |
    */

    'auto_render' => true,

    /*
    |--------------------------------------------------------------------------
    | Auto-Persist State
    |--------------------------------------------------------------------------
    |
    | Global kill-switch. When false, auto-state is disabled for every
    | component even if they declare $persistState = true. Opt-in is
    | per-component — this flag exists so you can flip the entire app off
    | during debugging without touching component classes.
    |
    | Per-component opt-in:
    |     protected bool $persistState = true;
    |
    | Reserved framework properties (renderView, renderData, etc.) are
    | never persisted. Per-property opt-out:
    |     protected array $transient = ['scratch', 'tempInput'];
    |
    */

    'persist_state' => true,

    /*
    |--------------------------------------------------------------------------
    | Instance ID Parameter Name
    |--------------------------------------------------------------------------
    |
    | When persist_state is on, each rendered component is wrapped in an
    | envelope that carries a unique instance ID via hx-vals. This is the
    | name of the request parameter used to identify which instance the
    | request belongs to.
    |
    */

    'instance_id_param' => '_hxid',

    /*
    |--------------------------------------------------------------------------
    | Fragment Scope Parameter Name
    |--------------------------------------------------------------------------
    |
    | When a component is mounted with a fragment subset
    | (@widget('Foo', fragments: ['bar'])) the wrapper carries this param
    | so subsequent HTMX interactions stay scoped to those fragments.
    | The view author doesn't have to think about it.
    |
    */

    'fragments_param' => '_hxfrags',

    /*
    |--------------------------------------------------------------------------
    | Embed-Site Render Override Parameter Names
    |--------------------------------------------------------------------------
    |
    | When @widget passes value:'prop' or full: true, the envelope writes
    | these into hx-vals so every subsequent HTMX action carries the
    | per-instance override. The kernel reads them back and applies them
    | before any method-level #[RenderValue] / #[RenderFragment] attribute.
    |
    */

    'value_property_param' => '_hxvalue',
    'full_render_param'    => '_hxfull',

    /*
    |--------------------------------------------------------------------------
    | State Max Instances Per Component
    |--------------------------------------------------------------------------
    |
    | Per-component LRU cap for persisted instance state. When a session
    | exceeds this many active instances of a single component class, the
    | oldest are evicted to keep session memory bounded. Set to a higher
    | number if you legitimately render many independent widgets per page.
    |
    */

    'state_max_instances' => 50,

    /*
    |--------------------------------------------------------------------------
    | HX-Request Header Check
    |--------------------------------------------------------------------------
    |
    | When enabled, the kernel rejects any request that doesn't carry the
    | "HX-Request: true" header HTMX sends automatically. Convenience guard,
    | not a security guarantee — the header is trivially spoofable.
    |
    */

    'check_hx_header' => false,

    /*
    |--------------------------------------------------------------------------
    | CSRF Protection
    |--------------------------------------------------------------------------
    |
    | When enabled, non-GET requests are verified against the session CSRF
    | token. Token must be supplied as body param "_csrf" or header
    | "X-CSRF-Token".
    |
    | Defaults to TRUE so the shipped config is safe for production. Flip
    | to false locally if you're testing without a session-aware HTTP
    | client, but please leave it on for real traffic.
    |
    */

    'csrf' => true,

    /*
    |--------------------------------------------------------------------------
    | NProgress on Actions
    |--------------------------------------------------------------------------
    */

    'nprogress_on_actions' => false,

    /*
    |--------------------------------------------------------------------------
    | Lazy Placeholder
    |--------------------------------------------------------------------------
    |
    | Default Blade view used as the placeholder while a lazy-loaded widget
    | fetches its real HTML. Components can override via the
    | $lazyPlaceholder property.
    |
    */

    'lazy_placeholder' => 'components.htmx.shimmer',

];
