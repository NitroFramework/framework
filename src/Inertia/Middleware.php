<?php

namespace Nitro\Inertia;

use Nitro\Facades\Inertia;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Inertia\Props\OnceProp;
use Nitro\Inertia\Support\Header;
use Nitro\Inertia\Support\SessionKey;

/**
 * The protocol's request and response rules.
 *
 * An application subclasses this to declare its shared props, its root
 * template and how the asset version is computed. The handling below is the
 * part that is the same everywhere: it is what turns a stale-asset request
 * into a hard reload, a 302-after-PUT into a 303, and an empty 200 into a
 * redirect back — each of which is a case the client cannot recover from on
 * its own.
 */
class Middleware
{
    /** The template a first visit is rendered into. */
    protected string $rootView = 'app';

    /**
     * Report every message for a field rather than only the first.
     *
     * Off by default because a form shows one message per input; a page that
     * lists them all can turn it on.
     */
    protected bool $withAllErrors = false;

    /**
     * The current asset version.
     *
     * Returning null disables the check. The build manifest is the usual
     * source: it changes when the assets do, which is exactly the condition
     * the client needs to know about.
     */
    public function version(Request $request): ?string
    {
        $manifest = app('paths')->public('build/manifest.json');

        return is_file($manifest) ? (string) hash_file('xxh128', $manifest) : null;
    }

    /**
     * Props every page receives.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            'errors' => Inertia::always($this->resolveValidationErrors($request)),
        ];
    }

    /**
     * Props shared once and then remembered by the client.
     *
     * Separate from {@see share()} because these are not re-sent: a value
     * that cannot change within a session — a permissions map, a currency
     * list — is worth computing once and never again.
     *
     * @return array<string, callable|\Nitro\Inertia\Props\OnceProp>
     */
    public function shareOnce(Request $request): array
    {
        return [];
    }

    public function rootView(Request $request): string
    {
        return $this->rootView;
    }

    /**
     * A callback that computes the page's `url`, or null for the default.
     *
     * The client compares that value against the address bar, so an
     * application behind a proxy that rewrites paths states what the browser
     * actually sees.
     */
    public function urlResolver(): ?\Closure
    {
        return null;
    }

    public function handle(Request $request, callable $next): Response
    {
        $factory = app(ResponseFactory::class);

        $factory->version(fn (): ?string => $this->version($request));
        $factory->share($this->share($request));
        $factory->setRootView($this->rootView($request));

        foreach ($this->shareOnce($request) as $key => $value) {
            if ($value instanceof OnceProp) {
                $factory->share($key, $value);
            } else {
                $factory->shareOnce($key, $value);
            }
        }

        if ($urlResolver = $this->urlResolver()) {
            $factory->resolveUrlUsing($urlResolver);
        }

        /** @var Response $response */
        $response = $next($request);

        /*
         * The same URL answers with HTML or JSON depending on this header, so
         * any cache in front of the application has to keep them apart.
         */
        $response->header('Vary', Header::INERTIA);

        $isRedirect = $response->isRedirect();

        if ($isRedirect) {
            $this->reflash($request);
        }

        if (! $request->header(Header::INERTIA)) {
            return $response;
        }

        if ($request->method() === 'GET'
            && $request->header(Header::VERSION, '') !== $factory->getVersion()) {
            return $this->onVersionChange($request, $response);
        }

        if ($response->getStatusCode() === Response::HTTP_OK && $response->getContent() === '') {
            return $this->onEmptyResponse($request, $response);
        }

        /*
         * A browser repeats the original method when following a 302. After a
         * PUT, PATCH or DELETE that means the redirect target is requested
         * with that method too, which is almost never routed. 303 tells it to
         * use GET.
         */
        if ($response->getStatusCode() === Response::HTTP_REDIRECT
            && in_array($request->method(), ['PUT', 'PATCH', 'DELETE'], true)) {
            $response->setStatusCode(ResponseFactory::STATUS_SEE_OTHER);
        }

        if ($isRedirect && $this->redirectHasFragment($response)) {
            return $this->onRedirectWithFragment($request, $response);
        }

        return $response;
    }

    /**
     * A version mismatch means the client is running assets this response was
     * not built for. Nothing can be salvaged from a soft navigation, so it is
     * told to load the URL properly.
     */
    public function onVersionChange(Request $request, Response $response): Response
    {
        session()->reflash();

        /*
         * One instance, not two lookups: the version lives on the factory
         * this request configured, so resolving twice risks answering with a
         * different object's idea of the version — which is an empty string.
         */
        $factory = app(ResponseFactory::class);

        return $factory
            ->location($request->fullUrl())
            ->header(Header::VERSION, $factory->getVersion());
    }

    /**
     * An empty 200 leaves the client with nothing to render. Sending it back
     * where it came from at least leaves the page it had.
     */
    public function onEmptyResponse(Request $request, Response $response): Response
    {
        return redirect()->back();
    }

    /**
     * A fragment in a redirect target is lost through fetch, so the client is
     * handed the location and performs the navigation itself.
     */
    public function onRedirectWithFragment(Request $request, Response $response): Response
    {
        return new Response('', ResponseFactory::STATUS_CONFLICT, [
            Header::REDIRECT => (string) $response->header('Location'),
        ]);
    }

    private function redirectHasFragment(Response $response): bool
    {
        return str_contains((string) $response->header('Location'), '#');
    }

    /**
     * Carry this layer's flash data over a redirect.
     *
     * A redirect is a response the page never saw, so anything flashed for it
     * has to survive one more request or it is lost between the two.
     */
    private function reflash(Request $request): void
    {
        $flashed = app(ResponseFactory::class)->getFlashed();

        if ($flashed !== []) {
            session()->flash(SessionKey::FLASH_DATA, $flashed);
        }
    }

    /**
     * Validation errors in the shape the client expects.
     *
     * The framework flashes a flat [field => message] map, which is already
     * what a page reads as errors.field — so this mostly passes it through.
     * An empty object rather than an empty array matters: JSON-encoding an
     * empty array gives `[]`, and the client indexes into it by field name.
     *
     * @return object
     */
    public function resolveValidationErrors(Request $request): object
    {
        $errors = session()->get('errors', []);

        if (! is_array($errors) || $errors === []) {
            return (object) [];
        }

        $resolved = [];

        foreach ($errors as $field => $messages) {
            $resolved[$field] = is_array($messages)
                ? ($this->withAllErrors ? $messages : ($messages[0] ?? ''))
                : $messages;
        }

        /*
         * One bag only. The framework has no named error bags, so the header
         * that would select one is honoured by nesting the whole map under
         * the name the client asked for.
         */
        $bag = $request->header(Header::ERROR_BAG);

        return (object) ($bag ? [$bag => (object) $resolved] : $resolved);
    }
}
