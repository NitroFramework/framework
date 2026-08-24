<?php

namespace Nitro\Livewire\Http;

use Nitro\Http\Response;

/**
 * The client runtime as an HTTP concern: where the bundled livewire.js lives,
 * how it is served, and the <script>/<style> tags that boot it.
 *
 * The runtime ships INSIDE the framework package (Http/dist/livewire.js) and is
 * served from here, so an app never carries its own copy in public/ (which would
 * drift per app, per deploy).
 */
class AssetController
{
    /**
     * Absolute path to the client runtime bundled inside the framework package
     * (src/Livewire/Http/dist/livewire.js). This is the single source of truth.
     */
    public function scriptPath(): string
    {
        return __DIR__ . '/dist/livewire.js';
    }

    /**
     * Serve the client runtime as an HTTP response for the /livewire/livewire.js
     * route. The far-future `immutable` cache header means a browser fetches it
     * exactly once and never revalidates; the `?v=` query in scripts() busts
     * that cache only when the bundled file actually changes (e.g. a framework
     * upgrade), so there is no per-request PHP cost after the first hit.
     */
    public function scriptResponse(): Response
    {
        $path = $this->scriptPath();
        $body = is_file($path) ? (string) file_get_contents($path) : '';

        return new Response($body, 200, [
            'Content-Type'  => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /** The <script> tag(s) that boot the Livewire client. */
    public function scripts(): string
    {
        $config = json_encode([
            'updateUri' => config('livewire.update_uri', '/livewire/update'),
            'uploadUri' => config('livewire.upload_uri', '/livewire/upload'),
            'csrf'      => function_exists('csrf_token') ? csrf_token() : '',
            // wire:navigate hover-prefetch tuning (config/livewire.php → navigate).
            // hoverDelayMs: hover this long before prefetching (Livewire uses 60).
            // cacheTtl: default cache window for a bare `.hover` (e.g. "30s"); a
            // per-link `wire:navigate.hover="30s"` overrides it. "0s" = one-shot.
            'navigate'  => [
                'hoverDelayMs' => (int) config('livewire.navigate.hover_delay_ms', 60),
                'cacheTtl'     => (string) config('livewire.navigate.cache_ttl', '0s'),
            ],
        ], JSON_UNESCAPED_SLASHES);

        // Cache-bust by the bundled file's mtime: the URL changes only when the
        // runtime shipped in the package changes, so a framework upgrade forces
        // a refetch while the immutable header keeps unchanged files cached.
        // Served from the framework route below — not the app's public/ dir.
        $value = @filemtime($this->scriptPath()) ?: '1';

        return '<script>window.Livewire=window.Livewire||{};window.Livewire.config=' . $config . ';</script>'
            . '<script src="/livewire/livewire.js?v=' . $value . '" defer></script>';
    }

    /** The <style> tag(s) for Livewire (e.g. wire:loading / wire:cloak). */
    public function styles(): string
    {
        return '<style>[wire\\:cloak]{display:none!important}</style>';
    }
}
