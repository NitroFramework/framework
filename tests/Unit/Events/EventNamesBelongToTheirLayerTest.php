<?php

namespace Tests\Unit\Events;

use Nitro\Cache\Events\CacheEvents;
use Nitro\Database\Events\DatabaseEvents;
use Nitro\Events\CoreEvents;
use Nitro\Routing\Events\RoutingEvents;
use Nitro\View\Events\ViewEvents;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A layer names its own events.
 *
 * CoreEvents declared all twenty-seven, which meant the Events layer named
 * fifteen others — Nitro\Events\CoreEvents::VIEW_RENDERING is the Events layer
 * describing View, the same shape as Routing once describing Livewire. The
 * practical cost is that a package cannot ship its own events: nitro-htmx
 * wanting htmx.boosted would have to edit a file inside the framework.
 *
 * So CoreEvents keeps what the core actually owns — the application, the
 * request, providers, exceptions — and every other layer declares its own.
 */
class EventNamesBelongToTheirLayerTest extends TestCase
{
    /** Catalogue => the prefixes it is allowed to declare. */
    private const CATALOGUES = [
        CoreEvents::class     => ['app.', 'request.', 'response.', 'provider.', 'exception.'],
        RoutingEvents::class  => ['route.'],
        DatabaseEvents::class => ['query.', 'transaction.'],
        ViewEvents::class     => ['view.'],
        CacheEvents::class    => ['cache.'],
    ];

    public function test_every_catalogue_exists(): void
    {
        foreach (array_keys(self::CATALOGUES) as $catalogue) {
            $this->assertTrue(class_exists($catalogue), "missing event catalogue {$catalogue}");
        }
    }

    /** No catalogue may name an event belonging to another layer. */
    public function test_a_catalogue_only_declares_its_own_prefixes(): void
    {
        foreach (self::CATALOGUES as $catalogue => $prefixes) {
            foreach ((new ReflectionClass($catalogue))->getConstants() as $constant => $name) {
                $owned = false;

                foreach ($prefixes as $prefix) {
                    $owned = $owned || str_starts_with($name, $prefix);
                }

                $this->assertTrue(
                    $owned,
                    "{$catalogue}::{$constant} is '{$name}', which belongs to another layer",
                );
            }
        }
    }

    /** CoreEvents specifically must have shed the other layers' names. */
    public function test_core_events_no_longer_names_other_layers(): void
    {
        $strays = [];

        foreach ((new ReflectionClass(CoreEvents::class))->getConstants() as $constant => $name) {
            foreach (['view.', 'cache.', 'query.', 'transaction.', 'route.'] as $foreign) {
                if (str_starts_with($name, $foreign)) {
                    $strays[] = "{$constant} ('{$name}')";
                }
            }
        }

        $this->assertSame([], $strays, 'CoreEvents still names layers it is not part of');
    }

    /** Nothing was lost in the move. */
    public function test_the_catalogues_together_still_cover_everything(): void
    {
        $names = [];

        foreach (array_keys(self::CATALOGUES) as $catalogue) {
            foreach ((new ReflectionClass($catalogue))->getConstants() as $name) {
                $names[] = $name;
            }
        }

        foreach ([
            'app.bootstrapping', 'app.bootstrapped', 'app.terminating',
            'request.received', 'request.handled', 'response.sending', 'response.sent',
            'provider.registering', 'provider.registered', 'provider.booting', 'provider.booted',
            'exception.occurred', 'exception.handled',
            'route.matched', 'route.dispatching', 'route.dispatched',
            'query.executing', 'query.executed',
            'transaction.beginning', 'transaction.committed', 'transaction.rolled_back',
            'view.rendering', 'view.rendered',
            'cache.hit', 'cache.missed', 'cache.written', 'cache.forgotten',
        ] as $required) {
            $this->assertContains($required, $names, "the split lost '{$required}'");
        }
    }

    /** Two catalogues declaring the same name would make listening ambiguous. */
    public function test_no_event_name_is_declared_twice(): void
    {
        $seen = [];

        foreach (array_keys(self::CATALOGUES) as $catalogue) {
            foreach ((new ReflectionClass($catalogue))->getConstants() as $constant => $name) {
                if (isset($seen[$name])) {
                    $this->fail("'{$name}' is declared by both {$seen[$name]} and {$catalogue}::{$constant}");
                }

                $seen[$name] = $catalogue . '::' . $constant;
            }
        }
    }

    /** Each catalogue lives in the layer it names. */
    public function test_each_catalogue_lives_in_its_own_layer(): void
    {
        foreach (array_keys(self::CATALOGUES) as $catalogue) {
            $namespace = (new ReflectionClass($catalogue))->getNamespaceName();

            $this->assertStringStartsWith('Nitro\\', $namespace);

            if ($catalogue !== CoreEvents::class) {
                $this->assertStringEndsWith(
                    '\\Events',
                    $namespace,
                    "{$catalogue} should live in its layer's Events namespace",
                );
            }
        }
    }
}
