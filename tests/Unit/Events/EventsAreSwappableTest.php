<?php

namespace Tests\Unit\Events;

use Nitro\Events\Concerns\DispatchesEvents;
use Nitro\Events\Contracts\Dispatcher as DispatcherContract;
use Nitro\Events\Contracts\TogglesEvents;
use Nitro\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Somebody must be able to bring their own event dispatcher.
 *
 * Events is the widest unguarded dependency in the framework: model events,
 * mail events, queue events and the routing hooks all reach a concrete class
 * with no contract, four of them through a `resolve('events')` string
 * where nothing checks a type at all.
 *
 * The contract is derived from what the framework actually calls, not from
 * what Dispatcher happens to expose publicly. Too small and it is the old
 * RouterInterface — an interface you can implement completely and still not
 * boot. Too large and every internal becomes a public promise.
 */
class EventsAreSwappableTest extends TestCase
{
    /** Everything a dispatcher must do for the framework to work. */
    private const REQUIRED = [
        'listen', 'dispatch', 'until', 'forget', 'hasListeners', 'subscribe',
    ];

    /** Optional, asked for with instanceof, with a defined fallback. */
    private const CAPABILITIES = [
        TogglesEvents::class => ['enable', 'disable', 'isEnabled'],
    ];

    /**
     * The composition root, which picks the default implementation.
     *
     * Somewhere has to name a concrete class or the application has no
     * dispatcher at all — the same way RoutingServiceProvider names Router.
     * That place may also call construction-time methods the contract does not
     * promise, such as setContainer(). The point of the exemption is that it is
     * one file and it is listed here, rather than four files and a comment.
     */
    private const COMPOSITION_ROOT = ['Foundation/Application.php'];

    public function test_the_events_layer_publishes_a_contract(): void
    {
        $this->assertTrue(
            interface_exists(DispatcherContract::class),
            'the Events layer must publish a contract for the object other layers hold',
        );
    }

    public function test_the_concrete_dispatcher_implements_it(): void
    {
        $this->assertInstanceOf(DispatcherContract::class, new Dispatcher());
    }

    /**
     * The contract says exactly what the framework needs — no less, no more.
     *
     * The upper bound matters as much as the lower one: a contract that copies
     * every public method of the concrete freezes its internals, and enable()
     * /disable()/flush() are nobody's business outside the layer.
     */
    public function test_the_contract_declares_the_required_surface_and_nothing_else(): void
    {
        $declared = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(DispatcherContract::class))->getMethods(),
        );

        sort($declared);
        $expected = self::REQUIRED;
        sort($expected);

        $this->assertSame($expected, $declared);
    }

    /**
     * Nothing outside the layer may name the concrete class.
     *
     * This is the check that would have caught RouterInterface being
     * decorative for months: the interface existed, and the Kernel type-hinted
     * Router anyway.
     */
    public function test_no_file_outside_the_events_layer_names_the_concrete(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $relative => $source) {
            if (str_starts_with($relative, 'Events/') || in_array($relative, self::COMPOSITION_ROOT, true)) {
                continue;
            }

            if (preg_match('/^use\s+Nitro\\\\Events\\\\Dispatcher(?:\s+as\s+\w+)?;/m', $source)) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, 'these depend on the concrete dispatcher instead of the contract');
    }

    /** And the exemption stays one file, so it cannot quietly become the rule. */
    public function test_only_the_composition_root_is_exempt(): void
    {
        $this->assertCount(1, self::COMPOSITION_ROOT);

        $this->assertMatchesRegularExpression(
            '/^use\s+Nitro\\\\Events\\\\Dispatcher(\s+as\s+\w+)?;/m',
            $this->sourceFiles()[self::COMPOSITION_ROOT[0]],
            'the exemption is listed for a file that does not use it — drop it',
        );
    }

    /**
     * Every method called on a dispatcher anywhere in the framework must be on
     * the contract, or guarded by an instanceof against a capability that
     * declares it. An unguarded call to something the contract omits is the
     * exact bug this whole exercise is about.
     */
    public function test_every_method_the_framework_calls_is_promised_somewhere(): void
    {
        $promised = self::REQUIRED;

        foreach (self::CAPABILITIES as $capability => $methods) {
            $this->assertTrue(interface_exists($capability), "missing capability contract {$capability}");
            $promised = array_merge($promised, $methods);
        }

        $called = [];

        foreach ($this->sourceFiles() as $relative => $source) {
            if (str_starts_with($relative, 'Events/') || in_array($relative, self::COMPOSITION_ROOT, true)) {
                continue;
            }

            /* Fetched from the container inline — the form that skips every type check. */
            $patterns = [
                '/resolve\(\s*\'events\'\s*\)\s*\??->(\w+)\(/',
            ];

            /*
             * Held in a property or variable, but only counted in a file that
             * holds an event dispatcher: $this->dispatcher is the
             * RouteDispatcher in the HTTP kernel, and counting its calls here
             * would be nonsense.
             */
            if (preg_match('/^use\s+Nitro\\\\Events\\\\(?:Contracts\\\\)?Dispatcher(?:\s+as\s+\w+)?;/m', $source)) {
                $patterns[] = '/\$(?:this->)?(?:dispatcher|events)\s*\??->(\w+)\(/';
            }

            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $source, $matches)) {
                    foreach ($matches[1] as $method) {
                        $called[$method][] = $relative;
                    }
                }
            }
        }

        $this->assertNotEmpty($called, 'expected to find dispatcher calls');

        $unpromised = array_diff(array_keys($called), $promised);

        $this->assertSame(
            [],
            array_values($unpromised),
            'called on a dispatcher but promised by no contract: ' . json_encode(
                array_intersect_key($called, array_flip($unpromised))
            ),
        );
    }

    /**
     * The end-to-end proof: a dispatcher the framework has never seen receives
     * an event through the trait every layer uses to emit them.
     */
    public function test_a_foreign_dispatcher_receives_events(): void
    {
        $foreign = $this->foreignDispatcher();

        $emitter = new class {
            use DispatchesEvents;

            public function emit(string $event, array $data): void
            {
                $this->event($event, $data);
            }
        };

        $emitter->setDispatcher($foreign);
        $emitter->emit('order.placed', ['id' => 7]);

        $this->assertSame([['order.placed', ['id' => 7]]], $foreign->dispatched);
    }

    /** A payload that costs something to build is still skipped when nobody listens. */
    public function test_a_foreign_dispatcher_is_asked_before_the_payload_is_built(): void
    {
        $foreign = $this->foreignDispatcher();
        $foreign->listening = false;

        $built = false;

        $emitter = new class {
            use DispatchesEvents;

            public function emitLazily(string $event, \Closure $payload): void
            {
                $this->eventLazy($event, $payload);
            }
        };

        $emitter->setDispatcher($foreign);
        $emitter->emitLazily('order.placed', function () use (&$built): array {
            $built = true;

            return ['expensive'];
        });

        $this->assertFalse($built, 'the payload builder ran even though nothing was listening');
        $this->assertSame([], $foreign->dispatched);
    }

    /**
     * A dispatcher that cannot be muted is still a valid dispatcher. The
     * toggle is a capability, so the framework asks before using it and has an
     * answer when it is absent.
     */
    public function test_a_dispatcher_without_the_toggle_capability_still_works(): void
    {
        $foreign = $this->foreignDispatcher();

        $this->assertNotInstanceOf(TogglesEvents::class, $foreign);

        $emitter = new class {
            use DispatchesEvents;

            public function enabled(): bool
            {
                return $this->shouldDispatchEvents();
            }
        };

        $emitter->setDispatcher($foreign);

        $this->assertTrue($emitter->enabled(), 'a dispatcher with no toggle counts as enabled');
    }

    /** And one that does implement it is believed. */
    public function test_the_toggle_capability_is_honoured_when_present(): void
    {
        $mutable = new class extends Dispatcher {};
        $mutable->disable();

        $emitter = new class {
            use DispatchesEvents;

            public function enabled(): bool
            {
                return $this->shouldDispatchEvents();
            }
        };

        $emitter->setDispatcher($mutable);

        $this->assertInstanceOf(TogglesEvents::class, $mutable);
        $this->assertFalse($emitter->enabled());
    }

    /** A dispatcher implementing only the contract, with no Nitro code in it. */
    private function foreignDispatcher(): object
    {
        return new class implements DispatcherContract {
            public array $dispatched = [];
            public bool $listening = true;

            public function listen(string|array $events, callable|string $listener): void {}
            public function subscribe(string|object $subscriber): void {}
            public function forget(string $event): void {}

            public function hasListeners(string $event): bool
            {
                return $this->listening;
            }

            public function dispatch(string|object $event, mixed $payload = [], bool $halt = false): mixed
            {
                $this->dispatched[] = [$event, $payload];

                return null;
            }

            public function until(string|object $event, mixed $payload = []): mixed
            {
                return $this->dispatch($event, $payload, true);
            }
        };
    }

    /** @return array<string, string> relative path => source */
    private function sourceFiles(): array
    {
        $root = dirname(__DIR__, 3) . '/src';

        $sources = [];

        $tree = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($tree as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $sources[$relative] = (string) file_get_contents($file->getPathname());
        }

        $this->assertNotEmpty($sources, 'expected to find the framework sources');

        return $sources;
    }
}
