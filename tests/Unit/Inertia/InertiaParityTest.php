<?php

namespace Tests\Unit\Inertia;

use Nitro\Container\Container;
use Nitro\Http\Request;
use Nitro\Inertia\Contracts\ProvidesInertiaProperty;
use Nitro\Inertia\Exceptions\ComponentNotFoundException;
use Nitro\Inertia\PropertyContext;
use Nitro\Inertia\ResponseFactory;
use Nitro\Inertia\Support\Header;
use Nitro\Session\ArraySessionHandler;
use Nitro\Session\Store;
use PHPUnit\Framework\TestCase;

/**
 * The parts of the protocol that were missing from the first pass.
 *
 * Each is a behaviour the reference implementation has and this one did not:
 * a deferred prop that fails without taking the page with it, a component name
 * checked before the client has to fail on it, an object that decides its own
 * serialized form, and the two hooks that let an application say what a page's
 * url is and what its component is called.
 */
class InertiaParityTest extends TestCase
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

    private function request(string $path = '/page', array $headers = []): Request
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
    private function page(array $props, string $component = 'Page', array $headers = []): array
    {
        return json_decode(
            $this->factory()->render($component, $props)
                ->toResponse($this->request('/page', $headers))
                ->getContent(),
            true
        );
    }

    // --- rescued props ------------------------------------------------------

    /**
     * A rescued deferred prop fails quietly.
     *
     * The page is already on screen when a deferred prop resolves, so letting
     * the failure out would replace a working page over a value it was built
     * to arrive without.
     */
    public function test_a_rescued_prop_reports_its_failure_instead_of_raising_it(): void
    {
        $request = $this->request('/page', [
            Header::PARTIAL_COMPONENT => 'Page',
            Header::PARTIAL_ONLY      => 'stats',
        ]);

        $page = json_decode(
            $this->factory()->render('Page', [
                'stats' => $this->factory()->defer(
                    static fn (): array => throw new \RuntimeException('upstream is down'),
                    'default',
                    rescue: true
                ),
            ])->toResponse($request)->getContent(),
            true
        );

        $this->assertNull($page['props']['stats']);
        $this->assertSame(['stats'], $page['rescuedProps']);
    }

    /** Without rescue, the failure is the caller's to handle. */
    public function test_an_unrescued_prop_still_raises(): void
    {
        $request = $this->request('/page', [
            Header::PARTIAL_COMPONENT => 'Page',
            Header::PARTIAL_ONLY      => 'stats',
        ]);

        $this->expectException(\RuntimeException::class);

        $this->factory()->render('Page', [
            'stats' => $this->factory()->defer(
                static fn (): array => throw new \RuntimeException('upstream is down')
            ),
        ])->toResponse($request);
    }

    // --- single-prop providers ---------------------------------------------

    /** An object can decide what it serializes to, and is told where it sits. */
    public function test_a_single_prop_provider_decides_its_own_value(): void
    {
        $provider = new class implements ProvidesInertiaProperty {
            public function toInertiaProperty(PropertyContext $prop): mixed
            {
                return [
                    'key'      => $prop->key,
                    'sawTitle' => $prop->props['title'] ?? null,
                    'path'     => $prop->request->path(),
                ];
            }
        };

        $page = $this->page(['title' => 'Reports', 'summary' => $provider]);

        $this->assertSame('summary', $page['props']['summary']['key']);
        $this->assertSame('Reports', $page['props']['summary']['sawTitle']);
        $this->assertSame('/page', $page['props']['summary']['path']);
    }

    // --- component transformation ------------------------------------------

    public function test_component_names_can_be_rewritten(): void
    {
        $this->factory()->transformComponentUsing(
            static fn (string $component): string => 'Admin/' . $component
        );

        $this->assertSame('Admin/Page', $this->page([])['component']);
    }

    /** A transformer returning nothing leaves the name alone. */
    public function test_a_transformer_returning_null_keeps_the_original_name(): void
    {
        $this->factory()->transformComponentUsing(static fn (): ?string => null);

        $this->assertSame('Page', $this->page([])['component']);
    }

    /** An enum names a component too, which is how a app avoids stringly-typed pages. */
    public function test_a_backed_enum_can_name_the_component(): void
    {
        $page = json_decode(
            $this->factory()->render(ParityComponent::Reports)->toResponse($this->request())->getContent(),
            true
        );

        $this->assertSame('Reports/Index', $page['component']);
    }

    // --- url resolution -----------------------------------------------------

    /**
     * The url the client compares against the address bar can be overridden.
     *
     * An application behind a proxy that strips a path prefix has to report
     * what the browser sees, not what the server received.
     */
    public function test_the_page_url_can_be_resolved_by_the_application(): void
    {
        $this->factory()->resolveUrlUsing(
            static fn (Request $request): string => '/prefixed' . $request->path()
        );

        $this->assertSame('/prefixed/page', $this->page([])['url']);
    }

    // --- component existence ------------------------------------------------

    /**
     * A mistyped component is caught on the server, where the name is still a
     * value that can be reported.
     */
    public function test_a_missing_component_can_be_refused_before_it_reaches_the_client(): void
    {
        $pages = sys_get_temp_dir() . '/nitro-inertia-pages-' . bin2hex(random_bytes(3));
        mkdir($pages . '/Reports', 0777, true);
        file_put_contents($pages . '/Reports/Index.jsx', '');

        $this->container->instance('config', new class($pages) {
            public function __construct(private string $pages)
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'inertia.pages.ensure_pages_exist' => true,
                    'inertia.pages.paths'              => [$this->pages],
                    'inertia.pages.extensions'         => ['jsx'],
                    default                            => $default,
                };
            }
        });

        // The one that exists renders.
        $this->assertSame('Reports/Index', $this->page([], 'Reports/Index')['component']);

        try {
            $this->factory()->render('Reports/Missing');
            $this->fail('a component with no module must be refused');
        } catch (ComponentNotFoundException $exception) {
            $this->assertStringContainsString('Reports/Missing', $exception->getMessage());
        } finally {
            @unlink($pages . '/Reports/Index.jsx');
            @rmdir($pages . '/Reports');
            @rmdir($pages);
        }
    }

    // --- response-level flash ----------------------------------------------

    public function test_flash_can_be_attached_to_the_response(): void
    {
        $page = json_decode(
            $this->factory()->render('Page')
                ->flash('status', 'saved')
                ->toResponse($this->request())
                ->getContent(),
            true
        );

        $this->assertSame(['status' => 'saved'], $page['flash']);
    }
}

/** A component named by an enum rather than a string. */
enum ParityComponent: string
{
    case Reports = 'Reports/Index';
}
