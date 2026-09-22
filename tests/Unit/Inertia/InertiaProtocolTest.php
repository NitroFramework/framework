<?php

namespace Tests\Unit\Inertia;

use Nitro\Container\Container;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Inertia\Directive;
use Nitro\Inertia\Middleware;
use Nitro\Inertia\ResponseFactory;
use Nitro\Inertia\Support\Header;
use Nitro\Session\ArraySessionHandler;
use Nitro\Session\Store;
use PHPUnit\Framework\TestCase;

/**
 * The Inertia protocol, exercised where it makes decisions.
 *
 * The interesting behaviour is all conditional: a partial reload evaluates a
 * different set of props to a full one, a prop that opts out of the first load
 * must not be called, and several statuses have to be rewritten on the way out
 * or the client cannot act on them. Each of those is a branch, and a branch
 * that is never asserted is a branch that quietly stops working.
 */
class InertiaProtocolTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();

        $store = new Store('nitro_session', new ArraySessionHandler());
        $store->start();

        $this->container->instance('session', $store);
        $this->container->singleton('inertia', static fn (): ResponseFactory => new ResponseFactory());

        // The alias the provider registers. Without it the factory resolved by
        // class name is a second instance, and shared props and the asset
        // version set on one would be invisible to the other.
        $this->container->alias('inertia', ResponseFactory::class);

        Container::setInstance($this->container);
    }

    protected function tearDown(): void
    {
        Container::reset();
    }

    private function factory(): ResponseFactory
    {
        return $this->container->resolve('inertia');
    }

    /** A request carrying the protocol headers a client would send. */
    private function inertiaRequest(string $path = '/students', array $headers = []): Request
    {
        $request = new Request('GET', $path);

        $reflection = new \ReflectionProperty(Request::class, 'headers');
        $existing = $reflection->getValue($request);

        $normalised = [];
        foreach ([Header::INERTIA => 'true'] + $headers as $name => $value) {
            $normalised[strtolower($name)] = $value;
        }

        $reflection->setValue($request, array_merge((array) $existing, $normalised));

        return $request;
    }

    public function test_an_inertia_request_gets_the_page_as_json(): void
    {
        $response = $this->factory()
            ->render('Students/Index', ['count' => 30])
            ->toResponse($this->inertiaRequest());

        $this->assertSame('true', $response->header(Header::INERTIA));

        $page = json_decode($response->getContent(), true);

        $this->assertSame('Students/Index', $page['component']);
        $this->assertSame(30, $page['props']['count']);
        $this->assertSame('/students', $page['url']);
        $this->assertArrayHasKey('version', $page);
    }

    /** The URL the client compares against the address bar keeps its query. */
    public function test_the_page_url_carries_the_query_string(): void
    {
        $request = $this->inertiaRequest('/students?page=2&sort=name');

        $page = json_decode(
            $this->factory()->render('Students/Index')->toResponse($request)->getContent(),
            true
        );

        $this->assertSame('/students?page=2&sort=name', $page['url']);
    }

    /**
     * A prop that opts out of the first load must not be evaluated, not merely
     * omitted — the whole point is that the query never runs.
     */
    public function test_an_optional_prop_is_neither_sent_nor_called_on_a_full_load(): void
    {
        $called = false;

        $page = json_decode(
            $this->factory()->render('Reports/Index', [
                'cheap'     => 1,
                'expensive' => $this->factory()->optional(function () use (&$called) {
                    $called = true;

                    return 'computed';
                }),
            ])->toResponse($this->inertiaRequest('/reports'))->getContent(),
            true
        );

        $this->assertArrayHasKey('cheap', $page['props']);
        $this->assertArrayNotHasKey('expensive', $page['props']);
        $this->assertFalse($called, 'the callback must not run when the prop is not sent');
    }

    public function test_a_partial_reload_sends_only_the_props_it_named(): void
    {
        $request = $this->inertiaRequest('/reports', [
            Header::PARTIAL_COMPONENT => 'Reports/Index',
            Header::PARTIAL_ONLY      => 'expensive',
        ]);

        $page = json_decode(
            $this->factory()->render('Reports/Index', [
                'cheap'     => 1,
                'expensive' => $this->factory()->optional(static fn (): string => 'computed'),
            ])->toResponse($request)->getContent(),
            true
        );

        $this->assertSame(['expensive' => 'computed'], $page['props']);
    }

    /**
     * Partial-ness is per component. A client still on one page asking for
     * props while navigating to another must get the new page in full.
     */
    public function test_a_partial_reload_for_another_component_is_treated_as_a_full_load(): void
    {
        $request = $this->inertiaRequest('/students', [
            Header::PARTIAL_COMPONENT => 'Reports/Index',
            Header::PARTIAL_ONLY      => 'expensive',
        ]);

        $page = json_decode(
            $this->factory()->render('Students/Index', ['count' => 30])->toResponse($request)->getContent(),
            true
        );

        $this->assertSame(['count' => 30], $page['props']);
    }

    public function test_a_partial_reload_honours_except(): void
    {
        $request = $this->inertiaRequest('/students', [
            Header::PARTIAL_COMPONENT => 'Students/Index',
            Header::PARTIAL_EXCEPT    => 'heavy',
        ]);

        $page = json_decode(
            $this->factory()->render('Students/Index', [
                'light' => 'a',
                'heavy' => 'b',
            ])->toResponse($request)->getContent(),
            true
        );

        $this->assertSame(['light' => 'a'], $page['props']);
    }

    /** An always-prop survives a partial reload that did not ask for it. */
    public function test_an_always_prop_is_sent_even_when_not_requested(): void
    {
        $request = $this->inertiaRequest('/students', [
            Header::PARTIAL_COMPONENT => 'Students/Index',
            Header::PARTIAL_ONLY      => 'light',
        ]);

        $page = json_decode(
            $this->factory()->render('Students/Index', [
                'light'  => 'a',
                'heavy'  => 'b',
                'errors' => $this->factory()->always(['name' => 'Required.']),
            ])->toResponse($request)->getContent(),
            true
        );

        $this->assertArrayHasKey('errors', $page['props']);
        $this->assertArrayNotHasKey('heavy', $page['props']);
    }

    /**
     * A deferred prop is advertised rather than sent, grouped so the client
     * knows what to request together.
     */
    public function test_a_deferred_prop_is_advertised_by_group_and_left_out(): void
    {
        $page = json_decode(
            $this->factory()->render('Dashboard', [
                'totals' => 1,
                'slow'   => $this->factory()->defer(static fn (): string => 'later', 'stats'),
            ])->toResponse($this->inertiaRequest('/dashboard'))->getContent(),
            true
        );

        $this->assertArrayNotHasKey('slow', $page['props']);
        $this->assertSame(['stats' => ['slow']], $page['deferredProps']);
    }

    public function test_a_merge_prop_is_reported_so_the_client_appends(): void
    {
        $page = json_decode(
            $this->factory()->render('Feed', [
                'items' => $this->factory()->merge([1, 2, 3]),
            ])->toResponse($this->inertiaRequest('/feed'))->getContent(),
            true
        );

        $this->assertSame([1, 2, 3], $page['props']['items']);
        $this->assertSame(['items'], $page['mergeProps']);
    }

    public function test_a_deep_merge_prop_is_reported_separately(): void
    {
        $page = json_decode(
            $this->factory()->render('Feed', [
                'items' => $this->factory()->deepMerge(['rows' => [1]]),
            ])->toResponse($this->inertiaRequest('/feed'))->getContent(),
            true
        );

        $this->assertSame(['items'], $page['deepMergeProps']);
        $this->assertArrayNotHasKey('mergeProps', $page);
    }

    /** Shared props reach the page, and a dotted key arrives nested. */
    public function test_shared_props_are_merged_and_dotted_keys_nest(): void
    {
        $factory = $this->factory();
        $factory->share('auth.user', ['id' => 7]);
        $factory->share(['locale' => 'en']);

        $page = json_decode(
            $factory->render('Students/Index', ['count' => 1])->toResponse($this->inertiaRequest())->getContent(),
            true
        );

        $this->assertSame(['id' => 7], $page['props']['auth']['user']);
        $this->assertSame('en', $page['props']['locale']);
        $this->assertSame(1, $page['props']['count']);
        $this->assertContains('auth', $page['sharedProps']);
    }

    /** A partial reload can name a nested path. */
    public function test_a_partial_reload_can_name_a_nested_prop(): void
    {
        $factory = $this->factory();
        $factory->share('auth.user', ['id' => 7]);

        $request = $this->inertiaRequest('/students', [
            Header::PARTIAL_COMPONENT => 'Students/Index',
            Header::PARTIAL_ONLY      => 'auth.user',
        ]);

        $page = json_decode(
            $factory->render('Students/Index', ['count' => 1])->toResponse($request)->getContent(),
            true
        );

        $this->assertSame(['auth' => ['user' => ['id' => 7]]], $page['props']);
    }

    // --- middleware ---------------------------------------------------------

    private function middleware(): Middleware
    {
        return new class extends Middleware {
            public function version(Request $request): ?string
            {
                return 'v1';
            }
        };
    }

    public function test_the_response_varies_on_the_inertia_header(): void
    {
        $response = $this->middleware()->handle(
            new Request('GET', '/students'),
            static fn (): Response => Response::html('plain')
        );

        $this->assertSame(Header::INERTIA, $response->header('Vary'));
    }

    /**
     * A browser repeats the original method through a 302, so a redirect after
     * a write would be re-issued as that write. 303 forces a GET.
     */
    public function test_a_redirect_after_a_write_becomes_a_see_other(): void
    {
        $request = $this->inertiaRequest('/students', [Header::VERSION => 'v1']);

        $reflection = new \ReflectionProperty(Request::class, 'method');
        $reflection->setValue($request, 'PUT');

        $response = $this->middleware()->handle(
            $request,
            static fn (): Response => new Response('', Response::HTTP_REDIRECT, ['Location' => '/students'])
        );

        $this->assertSame(303, $response->getStatusCode());
    }

    /**
     * Stale assets cannot be reconciled by a soft navigation, so the client is
     * told to load the URL properly.
     */
    public function test_a_version_mismatch_forces_a_hard_reload(): void
    {
        $request = $this->inertiaRequest('/students', [Header::VERSION => 'stale']);

        $this->container->instance('request', $request);

        $response = $this->middleware()->handle(
            $request,
            static fn (): Response => Response::html('ignored')
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('v1', $response->header(Header::VERSION));
        $this->assertNotNull($response->header(Header::LOCATION));
    }

    /** A matching version is left alone. */
    public function test_a_matching_version_passes_through(): void
    {
        $request = $this->inertiaRequest('/students', [Header::VERSION => 'v1']);

        $response = $this->middleware()->handle(
            $request,
            static fn (): Response => Response::html('page')
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('page', $response->getContent());
    }

    /**
     * Validation errors are shared as an object, not an array.
     *
     * An empty PHP array encodes to `[]`, and the client indexes into errors
     * by field name — so an empty error set has to encode to `{}`.
     */
    public function test_errors_are_shared_as_an_object_even_when_empty(): void
    {
        $shared = $this->middleware()->share(new Request('GET', '/students'));

        $this->assertArrayHasKey('errors', $shared);
        $this->assertSame('{}', json_encode(($shared['errors'])()));
    }

    public function test_flashed_errors_are_reduced_to_one_message_per_field(): void
    {
        session()->flash('errors', ['email' => ['Taken.', 'Also invalid.'], 'name' => 'Required.']);

        $errors = $this->middleware()->resolveValidationErrors(new Request('POST', '/students'));

        $this->assertSame('Taken.', $errors->email);
        $this->assertSame('Required.', $errors->name);
    }

    // --- directive ----------------------------------------------------------

    /**
     * The markup is the client's contract: it finds the page by the script's
     * data-page attribute and mounts on the div of that id.
     */
    public function test_the_directive_emits_the_markup_the_client_looks_for(): void
    {
        $compiled = Directive::compile('');

        $page = ['component' => 'Students/Index', 'props' => []];
        $rendered = $this->renderCompiled($compiled, $page);

        $this->assertStringContainsString('<script data-page="app" type="application/json">', $rendered);
        $this->assertStringContainsString('<div id="app"></div>', $rendered);
        $this->assertStringContainsString('"component":"Students/Index"', $rendered);
    }

    public function test_the_directive_accepts_a_custom_root_id(): void
    {
        $rendered = $this->renderCompiled(Directive::compile("'root'"), ['component' => 'X']);

        $this->assertStringContainsString('data-page="root"', $rendered);
        $this->assertStringContainsString('<div id="root"></div>', $rendered);
    }

    /** A prop holding markup cannot break out of the script tag. */
    public function test_the_directive_escapes_angle_brackets_in_props(): void
    {
        $rendered = $this->renderCompiled(
            Directive::compile(''),
            ['component' => 'X', 'props' => ['bio' => '</script><script>alert(1)</script>']]
        );

        $this->assertStringNotContainsString('</script><script>alert(1)', $rendered);
        $this->assertStringContainsString('<', $rendered);
    }

    /** Run the compiled directive with $page in scope, as Blade would. */
    private function renderCompiled(string $compiled, array $page): string
    {
        $file = tempnam(sys_get_temp_dir(), 'inertia-directive-') . '.php';
        file_put_contents($file, $compiled);

        ob_start();
        (static function () use ($file, $page): void {
            require $file;
        })();
        $output = (string) ob_get_clean();

        @unlink($file);

        return $output;
    }
}
