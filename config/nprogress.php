<?php

/*
|--------------------------------------------------------------------------
| NProgress Configuration
|--------------------------------------------------------------------------
|
| One top-bar progress indicator (the vendored NProgress library) shared by
| both HTMX requests and Livewire wire:navigate SPA navigations, with separate
| settings per context. Read at render-time and inlined as JS in the layout.
|
| Every key under `visual` can be overridden per context — put a key inside
| `htmx` or `livewire` to give that context a different value.
|
*/

return [
    /*
    | Master switch — loads the NProgress assets and runs the wiring. Turn off
    | to disable the progress bar entirely (both contexts).
    */
    'enabled' => true,

    /*
    | Shared visual defaults for both contexts.
    */
    'visual' => [
        'color'        => '#2299dd', // bar colour
        'height'       => '3px',     // bar thickness
        'speed'        => 250,       // rise/fade animation speed (ms)
        'minimum'      => 0.1,       // starting progress (0.0 – 1.0)
        'trickle'      => true,      // auto-creep forward while a request is pending
        'trickleSpeed' => 200,       // ms between trickle increments
        'easing'       => 'ease',    // CSS easing for the bar animation
        'showSpinner'  => false,     // the little corner spinner
    ],

    /*
    | HTMX requests. Fires only when the triggering element matches a rule, so
    | in-component htmx (counter buttons, form inputs) is excluded by default;
    | true SPA navigations (hx-push-url="true") are included. Any `visual` key
    | may be overridden here.
    */
    'htmx' => [
        'enabled'         => false,
        'min_duration_ms' => 70, // don't flash the bar on sub-70ms responses
        'triggers'        => [
            ['attribute' => 'hx-push-url', 'value' => 'true'],
        ],

        // Visual overrides for HTMX:
        'color' => '#29d',
    ],

    /*
    | Livewire wire:navigate SPA navigations. Fires on every wire:navigate.
    | Any `visual` key may be overridden here.
    */
    'livewire' => [
        'enabled'         => true,
        'min_duration_ms' => 0, // show immediately

        // Visual overrides for Livewire:
        'color' => '#2299dd',
        'speed' => 160,
    ],
];
