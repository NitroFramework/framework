<?php

namespace Nitro\Inertia;

use Nitro\Http\Contracts\Responsable;
use Nitro\Http\Request;
use Nitro\Http\Response as HttpResponse;
use Nitro\Inertia\Support\Header;
use Nitro\Inertia\Support\SessionKey;
use Nitro\Support\Str;

/**
 * One Inertia page, before it is known whether the client wants it as HTML or
 * as JSON.
 *
 * Both answers carry the same page object — component, props, url, version.
 * A first visit gets it embedded in the root template so the browser has
 * something to render; every visit after that gets it as JSON, because the
 * application is already running and only needs the data. That the two are
 * built from one structure is what keeps them from drifting.
 */
class Response implements Responsable
{
    /** @var array<string, mixed> */
    private array $viewData = [];

    private bool $clearHistory;

    private bool $preserveFragment;

    /**
     * @param array<array-key, mixed> $sharedProps
     * @param array<array-key, mixed> $props
     */
    public function __construct(
        private string $component,
        private array $sharedProps,
        private array $props,
        private string $rootView = 'app',
        private string $version = '',
        private bool $encryptHistory = false,
        private ?\Closure $urlResolver = null,
    ) {
        /*
         * Pulled rather than read: both flags are set for the next response
         * only, so leaving them in the session would apply them again on the
         * page after this one.
         */
        $this->clearHistory = (bool) session()->pull(SessionKey::CLEAR_HISTORY, false);
        $this->preserveFragment = (bool) session()->pull(SessionKey::PRESERVE_FRAGMENT, false);
    }

    /**
     * Add props to the page.
     *
     * @param  string|array<string, mixed> $key
     */
    public function with(string|array $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->props = array_merge($this->props, $key);
        } else {
            $this->props[$key] = $value;
        }

        return $this;
    }

    /**
     * Add data for the root template rather than for the page.
     *
     * Useful for things the document needs but the application does not — a
     * meta description, an Open Graph tag — which would otherwise have to be
     * sent as a prop and then ignored.
     *
     * @param  string|array<string, mixed> $key
     */
    public function withViewData(string|array $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->viewData = array_merge($this->viewData, $key);
        } else {
            $this->viewData[$key] = $value;
        }

        return $this;
    }

    /** Render into a different root template than the configured one. */
    public function rootView(string $rootView): self
    {
        $this->rootView = $rootView;

        return $this;
    }

    /**
     * Flash data onto this response.
     *
     * The chainable form of {@see ResponseFactory::flash()}, for the common
     * case of a message that belongs to the page being returned rather than
     * to some later one.
     *
     * @param  string|array<string, mixed> $key
     */
    public function flash(string|array $key, mixed $value = null): self
    {
        app('inertia')->flash($key, $value);

        return $this;
    }

    public function toResponse(Request $request): HttpResponse
    {
        $resolver = new PropsResolver($request, $this->component);

        [$props, $metadata] = $resolver->resolve($this->sharedProps, $this->props);

        $page = array_merge([
            'component' => $this->component,
            'props'     => $props,
            'url'       => $this->url($request),
            'version'   => $this->version,
        ], $metadata, $this->flags($request), $this->flashData());

        if ($request->header(Header::INERTIA)) {
            return HttpResponse::json($page)->header(Header::INERTIA, 'true');
        }

        return response()->view($this->rootView, $this->viewData + ['page' => $page]);
    }

    /**
     * Flash data for this page, pulled from the session.
     *
     * Consumed rather than read: it belongs to this response, and leaving it
     * behind would show the same message again on the page after this one.
     * Omitted entirely when empty, so a page object does not carry an empty
     * key on every render.
     *
     * @return array<string, mixed>
     */
    private function flashData(): array
    {
        $flash = app('inertia')->pullFlashed();

        return $flash === [] ? [] : ['flash' => $flash];
    }

    /**
     * The one-shot flags, each omitted unless set.
     *
     * @return array<string, bool>
     */
    private function flags(Request $request): array
    {
        return array_filter([
            'clearHistory'     => $this->clearHistory,
            'encryptHistory'   => $this->encryptHistory,
            'preserveFragment' => $this->preserveFragment,
        ]);
    }

    /**
     * The page's URL, relative to the host, with its trailing slash kept.
     *
     * The client compares this against the address bar, so /users and /users/
     * have to stay distinct — collapsing them makes the history entry
     * disagree with the URL and the back button behave oddly.
     */
    private function url(Request $request): string
    {
        if ($this->urlResolver !== null) {
            return (string) ($this->urlResolver)($request);
        }

        $url = Str::start($request->path(), '/');
        $query = $request->queryString();

        return $query === '' ? $url : $url . '?' . $query;
    }
}
