<?php

namespace Nitro\Htmx\Support;

use Nitro\Http\Response;

/**
 * Owns and serves the HTMX component runtime (hx-component.js), the same way
 * the Livewire layer serves its own runtime. The script is bundled inside the
 * framework package (src/Htmx/dist/hx-component.js) and served from the
 * GET /nitro/hx-component.js route, so an app never keeps a copy in public/js
 * (which would drift per app). Apps emit the tag with the @htmxScripts
 * directive; the /nitro/hx-component.js route is what actually serves the file.
 */
class HtmxAssets
{
    /**
     * Absolute path to the runtime bundled in the framework package. This is the
     * single source of truth — the file ships with nitro/framework and is served
     * from here, so every app runs the runtime of its installed version.
     */
    public static function scriptPath(): string
    {
        return dirname(__DIR__) . '/dist/hx-component.js';
    }

    /**
     * Serve the runtime as an HTTP response for the /nitro/hx-component.js route.
     * The far-future `immutable` header means a browser fetches it exactly once
     * and never revalidates; the `?v=` query in scriptTag() busts that cache only
     * when the bundled file changes (e.g. a framework upgrade), so there is no
     * per-request PHP cost after the first hit.
     */
    public function scriptResponse(): Response
    {
        $path = self::scriptPath();
        $body = is_file($path) ? (string) file_get_contents($path) : '';

        return new Response($body, 200, [
            'Content-Type'  => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * The <script> tag emitted by @htmxScripts. Points at the framework route,
     * not the app's public/ dir. Deliberately NOT deferred, matching the tag's
     * historical load position at the end of <body>.
     */
    public function scriptTag(): string
    {
        $v = @filemtime(self::scriptPath()) ?: '1';

        return '<script src="/nitro/hx-component.js?v=' . $v . '"></script>';
    }

    /** Absolute path to the bundled NProgress integration glue. */
    public static function nprogressScriptPath(): string
    {
        return dirname(__DIR__) . '/dist/nitro-nprogress.js';
    }

    /** Serve the NProgress integration for the /nitro/nprogress.js route. */
    public function nprogressScriptResponse(): Response
    {
        $path = self::nprogressScriptPath();
        $body = is_file($path) ? (string) file_get_contents($path) : '';

        return new Response($body, 200, [
            'Content-Type'  => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * The markup emitted by @nprogressScripts: inject the app's nprogress config
     * as a global, then load the framework-served glue (which drives NProgress
     * off the navigation event seam). Emits nothing when nprogress is disabled or
     * unconfigured, so the directive is safe to always place in a layout.
     *
     * The glue is `defer` and reads window.NitroNProgress, so it must run after
     * the vendor NProgress script — place @nprogressScripts after it.
     */
    public function nprogressScriptTag(): string
    {
        $config = function_exists('config') ? config('nprogress') : null;

        if (!is_array($config) || empty($config['enabled'])) {
            return '';
        }

        $v    = @filemtime(self::nprogressScriptPath()) ?: '1';
        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return '<script>window.NitroNProgress = ' . $json . ';</script>'
            . '<script defer src="/nitro/nprogress.js?v=' . $v . '"></script>';
    }
}
