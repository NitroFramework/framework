<?php

namespace Tests\Unit\Inertia;

use Nitro\Container\Container;
use Nitro\Database\Query\Paginator;
use Nitro\Http\Request;
use Nitro\Inertia\Contracts\ProvidesInertiaProperties;
use Nitro\Inertia\RenderContext;
use Nitro\Inertia\ResponseFactory;
use Nitro\Inertia\ScrollMetadata;
use Nitro\Inertia\Support\Header;
use Nitro\Session\ArraySessionHandler;
use Nitro\Session\Store;
use PHPUnit\Framework\TestCase;

/**
 * The prop types that carry state between requests.
 *
 * Each one exists because the client has to be told something the props alone
 * cannot express: where a scroll has got to, that a value need not be sent
 * again, or that a group of props travels together. The metadata is therefore
 * the behaviour, and is what these assert.
 */
class InertiaPropTypesTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();

        $store = new Store('nitro_session', new ArraySessionHandler());
        $store->start();

        $this->container->instance('session', $store);
        $this->container->singleton('inertia', static fn (): ResponseFactory => new ResponseFactory());
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

    private function request(string $path = '/feed', array $headers = []): Request
    {
        $request = new Request('GET', $path);

        $reflection = new \ReflectionProperty(Request::class, 'headers');
        $existing = (array) $reflection->getValue($request);

        $normalised = [];
        foreach ([Header::INERTIA => 'true'] + $headers as $name => $value) {
            $normalised[strtolower($name)] = $value;
        }

        $reflection->setValue($request, array_merge($existing, $normalised));

        return $request;
    }

    /** @return array<string, mixed> */
    private function page(array $props, array $headers = [], string $component = 'Feed'): array
    {
        return json_decode(
            $this->factory()->render($component, $props)
                ->toResponse($this->request('/feed', $headers))
                ->getContent(),
            true
        );
    }

    private function paginator(int $currentPage, int $lastPage): Paginator
    {
        return new Paginator(
            items: [['id' => 1], ['id' => 2]],
            total: $lastPage * 2,
            perPage: 2,
            currentPage: $currentPage,
        );
    }

    // --- scroll -------------------------------------------------------------

    /**
     * A scroll prop renders with the page.
     *
     * It is Deferrable so it *can* be held back, but it must not be by
     * default — a list that defers its first page has nothing to scroll.
     */
    public function test_a_scroll_prop_is_sent_on_the_first_load(): void
    {
        $page = $this->page(['items' => $this->factory()->scroll($this->paginator(1, 3))]);

        $this->assertArrayHasKey('items', $page['props']);
        $this->assertArrayNotHasKey('deferredProps', $page);
    }

    /** The position comes from the paginator, with both ends reported. */
    public function test_a_scroll_prop_reports_where_the_sequence_is(): void
    {
        $page = $this->page(['items' => $this->factory()->scroll($this->paginator(2, 3))]);

        $this->assertSame([
            'pageName'     => 'page',
            'previousPage' => 1,
            'nextPage'     => 3,
            'currentPage'  => 2,
        ], $page['scrollProps']['items']);
    }

    /** No next page on the last one, so the client stops asking. */
    public function test_the_last_page_reports_no_next_page(): void
    {
        $page = $this->page(['items' => $this->factory()->scroll($this->paginator(3, 3))]);

        $this->assertNull($page['scrollProps']['items']['nextPage']);
        $this->assertSame(2, $page['scrollProps']['items']['previousPage']);
    }

    /** Scrolling down appends, and only the rows are merged. */
    public function test_scrolling_down_appends_at_the_wrapper(): void
    {
        $page = $this->page(['items' => $this->factory()->scroll($this->paginator(2, 3))]);

        $this->assertContains('items.data', $page['mergeProps']);
        $this->assertArrayNotHasKey('prependProps', $page);
    }

    /** Scrolling up prepends instead, from the same endpoint. */
    public function test_scrolling_up_prepends_at_the_wrapper(): void
    {
        $page = $this->page(
            ['items' => $this->factory()->scroll($this->paginator(2, 3))],
            [Header::INFINITE_SCROLL_MERGE_INTENT => 'prepend']
        );

        $this->assertContains('items.data', $page['prependProps']);
    }

    /** A source that is not a paginator supplies its own position. */
    public function test_scroll_metadata_can_be_stated_rather_than_read(): void
    {
        $page = $this->page([
            'items' => $this->factory()->scroll(
                ['data' => [1, 2]],
                'data',
                static fn (): ScrollMetadata => new ScrollMetadata('cursor', 'abc', 'def', 'xyz')
            ),
        ]);

        $this->assertSame('cursor', $page['scrollProps']['items']['pageName']);
        $this->assertSame('def', $page['scrollProps']['items']['nextPage']);
    }

    /** Anything else is a mistake worth naming, not a silent empty position. */
    public function test_scroll_metadata_refuses_a_value_it_cannot_read(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ScrollMetadata::fromPaginator(['not' => 'a paginator']);
    }

    /** The value is resolved once, though it is needed twice. */
    public function test_a_scroll_prop_resolves_its_value_only_once(): void
    {
        $calls = 0;

        $prop = $this->factory()->scroll(function () use (&$calls): Paginator {
            $calls++;

            return $this->paginator(1, 2);
        });

        $this->page(['items' => $prop]);

        $this->assertSame(1, $calls, 'the query must not run again to read the page position');
    }

    // --- once ---------------------------------------------------------------

    public function test_a_once_prop_is_sent_and_advertised_the_first_time(): void
    {
        $page = $this->page(['permissions' => $this->factory()->once(static fn (): array => ['edit'])]);

        $this->assertSame(['edit'], $page['props']['permissions']);
        $this->assertSame('permissions', $page['onceProps']['permissions']['key']);
    }

    /** Once the client reports holding it, it stops being sent — or computed. */
    public function test_a_once_prop_the_client_holds_is_not_sent_or_evaluated(): void
    {
        $called = false;

        $page = $this->page(
            [
                'permissions' => $this->factory()->once(function () use (&$called): array {
                    $called = true;

                    return ['edit'];
                }),
            ],
            [Header::EXCEPT_ONCE_PROPS => 'permissions']
        );

        $this->assertArrayNotHasKey('permissions', $page['props']);
        $this->assertFalse($called);
    }

    /** A custom key is what the client remembers it by. */
    public function test_a_once_prop_can_be_remembered_under_its_own_key(): void
    {
        $page = $this->page([
            'permissions' => $this->factory()->once(static fn (): array => ['edit'])->as('acl'),
        ]);

        $this->assertSame('acl', $page['onceProps']['permissions']['key']);

        $held = $this->page(
            ['permissions' => $this->factory()->once(static fn (): array => ['edit'])->as('acl')],
            [Header::EXCEPT_ONCE_PROPS => 'acl']
        );

        $this->assertArrayNotHasKey('permissions', $held['props']);
    }

    /** Marked fresh, it goes out even though the client says it has it. */
    public function test_a_refreshed_once_prop_is_sent_anyway(): void
    {
        $page = $this->page(
            ['permissions' => $this->factory()->once(static fn (): array => ['edit'])->fresh()],
            [Header::EXCEPT_ONCE_PROPS => 'permissions']
        );

        $this->assertSame(['edit'], $page['props']['permissions']);
    }

    /** An expiry is reported in milliseconds, for the client to compare. */
    public function test_a_once_prop_can_carry_an_expiry(): void
    {
        $page = $this->page([
            'rates' => $this->factory()->once(static fn (): array => [])->until(60),
        ]);

        $expires = $page['onceProps']['rates']['expiresAt'];

        $this->assertGreaterThan(time() * 1000, $expires);
        $this->assertLessThanOrEqual((time() + 61) * 1000, $expires);
    }

    // --- property providers -------------------------------------------------

    /** An object passed without a key contributes its props as if written out. */
    public function test_a_property_provider_contributes_its_props(): void
    {
        $page = $this->page([
            'count' => 1,
            new class implements ProvidesInertiaProperties {
                public function toInertiaProperties(RenderContext $context): iterable
                {
                    return ['user' => ['id' => 7], 'locale' => 'en'];
                }
            },
        ]);

        $this->assertSame(1, $page['props']['count']);
        $this->assertSame(['id' => 7], $page['props']['user']);
        $this->assertSame('en', $page['props']['locale']);
    }

    /** It is told which page it is contributing to. */
    public function test_a_property_provider_is_given_the_render_context(): void
    {
        $page = $this->page([
            new class implements ProvidesInertiaProperties {
                public function toInertiaProperties(RenderContext $context): iterable
                {
                    return ['renderedFor' => $context->component, 'path' => $context->request->path()];
                }
            },
        ], [], 'Feed/Index');

        $this->assertSame('Feed/Index', $page['props']['renderedFor']);
        $this->assertSame('/feed', $page['props']['path']);
    }

    /** Shared providers work the same way, and count as shared props. */
    public function test_a_shared_property_provider_is_expanded(): void
    {
        $this->factory()->share([
            new class implements ProvidesInertiaProperties {
                public function toInertiaProperties(RenderContext $context): iterable
                {
                    return ['tenant' => 'acme'];
                }
            },
        ]);

        $page = $this->page(['count' => 1]);

        $this->assertSame('acme', $page['props']['tenant']);
    }
}
