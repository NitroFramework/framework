<?php

namespace Nitro\View;

use RuntimeException;

/**
 * Turns entry points into the tags that load them.
 *
 *     @vite(['resources/css/app.css', 'resources/js/app.js'])
 *
 * Two modes, and the whole point is that the template does not know which one
 * it is in:
 *
 *   - **Development.** `npm run dev` writes a hot file naming the dev server.
 *     While that file exists the tags point there, with Vite's client script
 *     first so a stylesheet edit swaps in without a reload.
 *
 *   - **Built.** `npm run build` writes a manifest mapping each source path to
 *     its hashed output. The hash is what lets the files be cached for a year
 *     and still change the moment they are rebuilt — the alternative is
 *     `?v=` query strings, which several CDNs and proxies ignore.
 *
 * A built asset that is missing from the manifest throws rather than emitting
 * a broken tag. A page that renders with no stylesheet looks like a CSS bug and
 * is really a deploy that never ran the build; saying so here saves the hour.
 */
class Vite
{
    /** @var array<string, mixed>|null */
    protected ?array $manifest = null;

    /**
     * @param string $publicPath Directory the built assets are served from.
     */
    public function __construct(
        protected string $publicPath,

        /** Where `npm run build` writes, relative to the public directory. */
        protected string $buildDirectory = 'build',

        /** Written by `npm run dev`; its presence is what "we are in dev" means. */
        protected string $hotFile = 'hot',
    ) {}

    /**
     * @param  string|array<int, string>  $entries
     */
    public function tags(string|array $entries): string
    {
        $entries = (array) $entries;

        return $this->isRunningHot()
            ? $this->devTags($entries)
            : $this->builtTags($entries);
    }

    /** Whether `npm run dev` is running. */
    public function isRunningHot(): bool
    {
        return is_file($this->publicPath . '/' . $this->hotFile);
    }

    /** The dev server's URL, as the hot file records it. */
    public function hotUrl(): string
    {
        return rtrim((string) file_get_contents($this->publicPath . '/' . $this->hotFile));
    }

    /**
     * Straight at the dev server, with Vite's client first.
     *
     * The client is what makes hot replacement work; without it the page loads
     * the right files once and then never notices another edit.
     *
     * @param  array<int, string>  $entries
     */
    protected function devTags(array $entries): string
    {
        $url = $this->hotUrl();

        $tags = [sprintf('<script type="module" src="%s/@vite/client"></script>', $url)];

        foreach ($entries as $entry) {
            $tags[] = $this->tagFor($url . '/' . ltrim($entry, '/'), $entry);
        }

        return implode("\n    ", $tags);
    }

    /**
     * The hashed files the build produced, plus the CSS any JS entry imports.
     *
     * That last part matters: a stylesheet imported from JavaScript has no tag
     * of its own, so without walking the manifest the page loads its script and
     * renders unstyled — in production only, where it is hardest to see.
     *
     * @param  array<int, string>  $entries
     */
    protected function builtTags(array $entries): string
    {
        $manifest = $this->manifest();
        $tags = [];
        $seen = [];

        foreach ($entries as $entry) {
            $chunk = $manifest[$entry] ?? throw new RuntimeException(
                "Vite: [{$entry}] is not in the manifest. Run `npm run build`."
            );

            foreach ($chunk['css'] ?? [] as $css) {
                if (! isset($seen[$css])) {
                    $seen[$css] = true;
                    $tags[] = $this->tagFor($this->asset($css), $css);
                }
            }

            $tags[] = $this->tagFor($this->asset($chunk['file']), $entry);
        }

        return implode("\n    ", $tags);
    }

    /** A stylesheet gets a link, anything else a module script. */
    protected function tagFor(string $url, string $entry): string
    {
        return $this->isStylesheet($entry)
            ? sprintf('<link rel="stylesheet" href="%s">', $url)
            : sprintf('<script type="module" src="%s"></script>', $url);
    }

    /** Determine whether a built asset is a stylesheet. */
    protected function isStylesheet(string $path): bool
    {
        return (bool) preg_match('/\.(css|less|sass|scss|styl|stylus|pcss|postcss)$/', $path);
    }

    /** Get the public URL for a built asset. */
    protected function asset(string $file): string
    {
        return '/' . trim($this->buildDirectory, '/') . '/' . ltrim($file, '/');
    }

    /**
     * @return array<string, mixed>
     */
    protected function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $path = $this->publicPath . '/' . trim($this->buildDirectory, '/') . '/.vite/manifest.json';

        if (! is_file($path)) {
            throw new RuntimeException(
                "Vite: no manifest at [{$path}]. Run `npm run build`, or `npm run dev` while developing."
            );
        }

        return $this->manifest = (array) json_decode((string) file_get_contents($path), true);
    }
}
