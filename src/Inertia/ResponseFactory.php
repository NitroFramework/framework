<?php

namespace Nitro\Inertia;

use BackedEnum;
use Closure;
use InvalidArgumentException;
use Nitro\Exceptions\ExceptionHandler;
use Nitro\Http\RedirectResponse;
use Nitro\Http\Request;
use Nitro\Http\Response as HttpResponse;
use Nitro\Inertia\Contracts\ProvidesInertiaProperties;
use Nitro\Inertia\Contracts\ProvidesScrollMetadata;
use Nitro\Inertia\Exceptions\ComponentNotFoundException;
use UnitEnum;
use Nitro\Inertia\Props\AlwaysProp;
use Nitro\Inertia\Props\DeferProp;
use Nitro\Inertia\Props\MergeProp;
use Nitro\Inertia\Props\OnceProp;
use Nitro\Inertia\Props\OptionalProp;
use Nitro\Inertia\Props\ScrollProp;
use Nitro\Inertia\Support\Header;
use Nitro\Inertia\Support\SessionKey;
use Nitro\Inertia\Support\Setting;
use Nitro\Support\Arr;

/**
 * The service behind `Inertia::` and `inertia()`.
 *
 * Holds what is true for every page of a request — the shared props, the root
 * template, the asset version — and builds a {@see Response} from a component
 * name and its own props. Everything a controller says is a page-level
 * statement; everything set here is an application-level one.
 */
class ResponseFactory
{
    /**
     * The status that tells the client library to leave the application.
     *
     * Declared here because the framework's Response defines only the handful
     * of codes it uses itself, and the protocol's meaning for 409 is specific
     * enough to be worth naming where it is used.
     */
    public const STATUS_CONFLICT = 409;

    /** The status a redirect must become after a PUT, PATCH or DELETE. */
    public const STATUS_SEE_OTHER = 303;

    private string $rootView = 'app';

    /** @var array<array-key, mixed> */
    private array $sharedProps = [];

    private Closure|string|null $version = null;

    private bool $clearHistory = false;

    private bool $encryptHistory = false;

    private ?Closure $urlResolver = null;

    private ?Closure $componentTransformer = null;

    /** Name the root template every page renders into. */
    public function setRootView(string $name): void
    {
        $this->rootView = $name;
    }

    /**
     * Add props carried by every response.
     *
     * The authenticated user and validation errors are the usual contents:
     * things every page needs and no controller should have to remember.
     * A dotted key nests, so share('auth.user', $u) arrives as auth.user.
     *
     * @param  string|array<array-key, mixed> $key
     */
    public function share(string|array $key, mixed $value = null): void
    {
        if (is_array($key)) {
            $this->sharedProps = array_merge($this->sharedProps, $key);

            return;
        }

        Arr::set($this->sharedProps, $key, $value);
    }

    /**
     * Read back what has been shared.
     *
     * @return mixed
     */
    public function getShared(?string $key = null, mixed $default = null)
    {
        return $key === null
            ? $this->sharedProps
            : Arr::get($this->sharedProps, $key, $default);
    }

    public function flushShared(): void
    {
        $this->sharedProps = [];
    }

    /**
     * Set the asset version, as a string or a closure evaluated on demand.
     *
     * The client sends the version it holds with every request. When it stops
     * matching, the assets it is running are stale and a soft navigation would
     * execute old JavaScript against a new page — so the middleware turns that
     * request into a hard reload instead.
     */
    public function version(Closure|string|null $version): void
    {
        $this->version = $version;
    }

    public function getVersion(): string
    {
        $version = $this->version instanceof Closure
            ? ($this->version)()
            : $this->version;

        return (string) $version;
    }

    /** Tell the next page to discard the client's history state. */
    public function clearHistory(): void
    {
        session()->put(SessionKey::CLEAR_HISTORY, true);
    }

    /** Keep the URL fragment across the next redirect. */
    public function preserveFragment(): void
    {
        session()->put(SessionKey::PRESERVE_FRAGMENT, true);
    }

    public function encryptHistory(bool $encrypt = true): void
    {
        $this->encryptHistory = $encrypt;
    }

    /**
     * A prop sent only when a partial reload names it.
     */
    public function optional(callable $callback): OptionalProp
    {
        return new OptionalProp($callback);
    }

    /**
     * A prop the page fetches for itself once it has rendered.
     */
    public function defer(callable $callback, string $group = 'default', bool $rescue = false): DeferProp
    {
        return new DeferProp($callback, $group, $rescue);
    }

    /** A prop whose next value is appended to what the client holds. */
    public function merge(mixed $value): MergeProp
    {
        return (new MergeProp($value))->merge();
    }

    /** As {@see merge()}, but combining nested structures rather than the top level. */
    public function deepMerge(mixed $value): MergeProp
    {
        return (new MergeProp($value))->deepMerge();
    }

    /** A prop sent with every response, partial reloads included. */
    public function always(mixed $value): AlwaysProp
    {
        return new AlwaysProp($value);
    }

    /**
     * A prop the client keeps, so later pages stop sending it.
     */
    public function once(callable $callback): OnceProp
    {
        return new OnceProp($callback);
    }

    /**
     * Share a prop the client remembers across navigations.
     *
     * Shared rather than page-level because the value is application-wide;
     * the difference from {@see share()} is that it stops being sent once the
     * client has it.
     */
    public function shareOnce(string $key, callable $callback): void
    {
        $this->share($key, $this->once($callback)->as($key));
    }

    /**
     * A page of a sequence the client extends by scrolling.
     *
     * The wrapper names the key holding the rows — 'data' for a paginator —
     * because only that part is merged; the counts beside it are replaced.
     *
     * @param ProvidesScrollMetadata|callable|null $metadata Where the position comes
     *   from, when the value is not a paginator.
     */
    public function scroll(mixed $value, string $wrapper = 'data', ProvidesScrollMetadata|callable|null $metadata = null): ScrollProp
    {
        return new ScrollProp($value, $wrapper, $metadata);
    }

    /**
     * Override how a page's `url` is computed.
     *
     * The client compares that value against the address bar, so an
     * application served under a path prefix or behind a proxy that rewrites
     * paths has to say what the browser actually sees.
     */
    public function resolveUrlUsing(?Closure $urlResolver = null): void
    {
        $this->urlResolver = $urlResolver;
    }

    /**
     * Rewrite component names on their way out.
     *
     * Lets a module prefix its own pages, or a rename be absorbed in one
     * place rather than in every controller that renders one.
     */
    public function transformComponentUsing(?Closure $componentTransformer = null): void
    {
        $this->componentTransformer = $componentTransformer;
    }

    /**
     * Build a page.
     *
     * @param  array<array-key, mixed>|ProvidesInertiaProperties $props
     */
    public function render(BackedEnum|UnitEnum|string $component, array|ProvidesInertiaProperties $props = []): Response
    {
        $component = $this->transformComponent($component);

        $component = match (true) {
            $component instanceof BackedEnum => (string) $component->value,
            $component instanceof UnitEnum   => $component->name,
            default                          => $component,
        };

        if (! is_string($component)) {
            throw new InvalidArgumentException('Component argument must be of type string or a string BackedEnum');
        }

        if ((bool) Setting::get('inertia.pages.ensure_pages_exist', false)) {
            $this->findComponentOrFail($component);
        }

        /*
         * A provider passed on its own arrives under a numeric key, which is
         * what tells the resolver to expand it into the props it contributes
         * rather than treat it as one prop's value.
         */
        if ($props instanceof ProvidesInertiaProperties) {
            $props = [$props];
        }

        return new Response(
            $component,
            $this->sharedProps,
            $props,
            $this->rootView,
            $this->getVersion(),
            $this->encryptHistory,
            $this->urlResolver,
        );
    }

    /**
     * Fail loudly when a page component does not exist.
     *
     * Off unless `inertia.pages.ensure_pages_exist` is set, because it costs
     * a filesystem search per render. Worth turning on in development, where
     * a mistyped component otherwise fails silently in the browser.
     *
     * @throws ComponentNotFoundException
     */
    protected function findComponentOrFail(string $component): void
    {
        $paths = (array) Setting::get('inertia.pages.paths', []);
        $extensions = (array) Setting::get('inertia.pages.extensions', ['jsx', 'tsx', 'vue', 'svelte']);

        foreach ($paths as $path) {
            foreach ($extensions as $extension) {
                if (is_file(rtrim((string) $path, '/\\') . DIRECTORY_SEPARATOR . $component . '.' . $extension)) {
                    return;
                }
            }
        }

        throw new ComponentNotFoundException("Inertia page component [{$component}] not found.");
    }

    /** Apply the component transformer, keeping the original if it returns nothing. */
    protected function transformComponent(BackedEnum|UnitEnum|string $component): BackedEnum|UnitEnum|string
    {
        if ($this->componentTransformer === null) {
            return $component;
        }

        return ($this->componentTransformer)($component) ?? $component;
    }

    /**
     * Send the client somewhere outside the application.
     *
     * A 302 would be followed by fetch and the body handed to the client,
     * which cannot make a page out of it. A 409 with the target in a header
     * tells the client library to leave and load the URL itself; a browser
     * that is not running the client gets an ordinary redirect.
     */
    public function location(string|RedirectResponse $url): HttpResponse
    {
        $target = $url instanceof RedirectResponse
            ? (string) $url->header('Location')
            : $url;

        $request = app('request');

        if ($request instanceof Request && $request->header(Header::INERTIA)) {
            return new HttpResponse('', self::STATUS_CONFLICT, [Header::LOCATION => $target]);
        }

        return new HttpResponse('', HttpResponse::HTTP_REDIRECT, ['Location' => $target]);
    }

    /**
     * Flash data onto the next Inertia response.
     *
     * Kept under this layer's own session key rather than the application's
     * flash bag, so a message meant for the page object cannot be consumed by
     * a Blade view first.
     *
     * @param  string|array<string, mixed> $key
     */
    public function flash(string|array $key, mixed $value = null): self
    {
        $existing = (array) session()->get(SessionKey::FLASH_DATA, []);
        $incoming = is_array($key) ? $key : [$key => $value];

        session()->flash(SessionKey::FLASH_DATA, array_merge($existing, $incoming));

        return $this;
    }

    /**
     * Decide how exceptions become responses.
     *
     * The callback is handed an {@see ExceptionResponse} for every failure
     * the handler renders and returns what to send. Calling render() on it
     * turns that failure into a page of the application; returning it
     * untouched leaves the original response alone, which is what a 500 in
     * production should usually stay as.
     *
     *     Inertia::handleExceptionsUsing(fn ($response) => match ($response->statusCode()) {
     *         404, 403 => $response->render('Errors/Show', ['status' => $response->statusCode()]),
     *         default  => $response,
     *     });
     */
    public function handleExceptionsUsing(callable $callback): void
    {
        app(ExceptionHandler::class)->respondUsing(
            function (mixed $response, \Throwable $exception, mixed $request) use ($callback): mixed {
                if (! $response instanceof HttpResponse || ! $request instanceof Request) {
                    return $response;
                }

                $result = $callback(new ExceptionResponse($exception, $request, $response));

                return $result instanceof ExceptionResponse ? $result->toResponse() : $result;
            }
        );
    }

    /**
     * Redirect back, as an Inertia visit.
     *
     * Sent through the framework's redirector so the response is one the
     * middleware can still turn into a 303 or a 409 on the way out.
     */
    public function back(int $status = HttpResponse::HTTP_REDIRECT, array $headers = [], string $fallback = '/'): RedirectResponse
    {
        return redirect()->back($fallback, $status)->withHeaders($headers);
    }

    /**
     * Read the flashed data without consuming it.
     *
     * @return array<string, mixed>
     */
    public function getFlashed(): array
    {
        return (array) session()->get(SessionKey::FLASH_DATA, []);
    }

    /**
     * Read the flashed data and clear it.
     *
     * @return array<string, mixed>
     */
    public function pullFlashed(): array
    {
        return (array) session()->pull(SessionKey::FLASH_DATA, []);
    }
}
