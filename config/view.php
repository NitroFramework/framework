<?php

return [
    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    |
    | Primary path where template files are stored. In the future, this could
    | be extended to support multiple paths or namespaced views.
    |
    */
    'paths' => resource_path('views'),

    /*
    |--------------------------------------------------------------------------
    | Template File Extensions
    |--------------------------------------------------------------------------
    |
    | Extensions a view name is looked for under, tried in this order within
    | each views directory. A '.md' file compiles through Blade first, so its
    | directives and expressions still work, and its output is then converted
    | to HTML.
    |
    */
    'extensions' => ['blade.php', 'md'],

    /*
    |--------------------------------------------------------------------------
    | Markdown
    |--------------------------------------------------------------------------
    |
    | Raw HTML in a Markdown document is escaped by default, because a document
    | is often the least trusted text on the page. Turn it on only for content
    | you write yourself. With hard breaks on, every newline becomes a <br>.
    |
    | A link written in Markdown is a plain anchor, so following one is a full
    | page load — which drops the application out of client-side navigation on
    | most of the links a content page has. Attributes listed here are added to
    | links that stay in the application. Set 'base_url' to have an absolute URL
    | to your own site count as internal too, and 'fragments' to include links
    | to a place on the same page.
    |
    */
    'markdown' => [
        'allow_html'      => false,
        'hard_breaks'     => false,
        'link_attributes' => ['wire:navigate' => true],
        'base_url'        => null,
        'fragments'       => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled View Cache Settings
    |--------------------------------------------------------------------------
    |
    | Controls how compiled views are cached. In production, caching should
    | always be enabled. In development, you might disable it or use
    | aggressive invalidation.
    |
    */
    'cache' => [
        // Enable/disable view compilation caching
        'enabled' => true,

        // Where compiled views are stored
        'path' => storage_path('cache/views'),

        // Cache expiry in seconds (0 = never expire based on time, only on file changes)
        'expiry' => 0,

        // Use PHP OpCode cache for additional performance
        'use_opcache' => false,

        // Use file locks during compilation to prevent race conditions
        'use_locks' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, provides detailed error messages and warnings.
    | Should be false in production.
    |
    */
    'debug_render' => false,
];