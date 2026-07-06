<?php

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
    | Setting this to an empty string currently causes route-matching issues
    | in this app, so keep a prefix unless that bug is resolved.
    |
    */

    'route_prefix' => '/hx',

    /*
    |--------------------------------------------------------------------------
    | URL & Payload Obfuscation
    |--------------------------------------------------------------------------
    |
    | obfuscation: hash component + action names in HTMX URLs.
    | encryption:  encrypt hx-vals payloads to prevent client-side tampering.
    |
    */

    'obfuscation' => false,
    'encryption'  => false,

    /*
    |--------------------------------------------------------------------------
    | Allowed HTTP Methods
    |--------------------------------------------------------------------------
    |
    | Methods the HTMX endpoint will respond to.
    |
    */

    'route_methods' => ['get', 'post'],

    /*
    |--------------------------------------------------------------------------
    | Component Namespace
    |--------------------------------------------------------------------------
    |
    | PSR-4 namespace where HTMX components live.
    |
    */

    'component_namespace' => 'App\\Htmx\\Components\\',

    /*
    |--------------------------------------------------------------------------
    | Default View Path Prefix
    |--------------------------------------------------------------------------
    |
    | When a component does not define $view, Nitro infers it from the class
    | name using this prefix.
    |
    | Counter      -> components.htmx.counter
    | StudentTable -> components.htmx.student-table
    |
    */

    'view_path_prefix' => 'components.htmx.',

    /*
    |--------------------------------------------------------------------------
    | Session Key Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix used by HTMX state stored in the session or cache.
    |
    */

    'session_prefix' => 'htmx_',

    /*
    |--------------------------------------------------------------------------
    | State Store
    |--------------------------------------------------------------------------
    |
    | session: use PHP session storage
    | cache:   use Nitro cache storage
    | array:   request-lifetime only, mainly useful for tests
    |
    */

    'state' => [
        // 'session' keeps component state in $_SESSION (in-memory during the
        // request, serialized once at request end). The previous 'cache'+'file'
        // setting did a disk read+write per widget per request — ~3ms each on
        // Windows, which was ~45ms of a 9-widget page's render. See worker-timing.
        'store'        => 'session',
        'cache_driver' => 'file',
        'ttl'          => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-Render
    |--------------------------------------------------------------------------
    |
    | When true, actions that do not explicitly return a Response or call
    | render()/value() will automatically render the component view.
    |
    */

    'auto_render' => true,

    /*
    |--------------------------------------------------------------------------
    | Auto-Persist State
    |--------------------------------------------------------------------------
    |
    | Global kill-switch for automatic component state persistence.
    |
    */

    'persist_state' => true,

    /*
    |--------------------------------------------------------------------------
    | HTMX Request Parameter Names
    |--------------------------------------------------------------------------
    |
    | Internal hx-* parameter names used across component requests.
    |
    */

    'instance_id_param'    => '_hxid',
    'fragments_param'      => '_hxfrags',
    'value_property_param' => '_hxvalue',
    'full_render_param'    => '_hxfull',

    /*
    |--------------------------------------------------------------------------
    | Navigation Defaults
    |--------------------------------------------------------------------------
    |
    | Default settings for Nitro's client-side navigation layer.
    |
    */

    'navigation' => [
        'cache'      => false,
        'prefetch'   => false,
        'target'     => '[data-nitro-nav-root]',
        'select'     => '[data-nitro-nav-root]',
        'select_oob' => '#perf-badge',
    ],

    /*
    |--------------------------------------------------------------------------
    | State Max Instances Per Component
    |--------------------------------------------------------------------------
    |
    | Per-component cap for persisted state entries.
    |
    */

    'state_max_instances' => 50,

    /*
    |--------------------------------------------------------------------------
    | HX-Request Header Check
    |--------------------------------------------------------------------------
    |
    | Reject non-HTMX requests that do not carry the HX-Request header.
    |
    */

    'check_hx_header' => false,

    /*
    |--------------------------------------------------------------------------
    | CSRF Protection
    |--------------------------------------------------------------------------
    |
    | Require CSRF validation for non-GET HTMX requests.
    |
    */

    'csrf' => false,

    /*
    |--------------------------------------------------------------------------
    | UI Defaults
    |--------------------------------------------------------------------------
    */

    'nprogress_on_actions' => false,
    'lazy_placeholder'     => 'components.htmx.shimmer',

];
