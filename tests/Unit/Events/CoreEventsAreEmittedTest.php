<?php

namespace Tests\Unit\Events;

use Nitro\Events\CoreEvents;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every name in CoreEvents must be fired by something.
 *
 * A constant here is a promise to a developer who writes
 * Event::listen(CoreEvents::QUERY_EXECUTED, …) and expects to be called.
 * Twenty-five of the twenty-seven fired nothing, so that listener waited
 * forever and nothing anywhere said why — the same silent-absence failure as
 * the router's own hooks, which had never fired in the framework's life.
 *
 * This is the guard that makes the list honest: adding a constant without an
 * emitter now fails, and so does deleting the last emitter of one.
 */
class CoreEventsAreEmittedTest extends TestCase
{
    public function test_every_declared_event_is_emitted_somewhere(): void
    {
        $unemitted = [];

        foreach ($this->declaredEvents() as $qualified => $name) {
            $constant = substr($qualified, strrpos($qualified, ':') + 1);

            if ($this->emittersOf($constant, $name) === []) {
                $unemitted[] = $qualified . " ('" . $name . "')";
            }
        }

        $this->assertSame([], $unemitted, "declared but never fired:\n  " . implode("\n  ", $unemitted));
    }

    /** The catalogue is not allowed to quietly shrink to whatever was easy. */
    public function test_the_catalogue_still_covers_the_core_lifecycle(): void
    {
        $declared = array_map(
            static fn (string $qualified): string => substr($qualified, strrpos($qualified, ':') + 1),
            array_keys($this->declaredEvents()),
        );

        foreach ([
            'APP_BOOTSTRAPPING', 'APP_BOOTSTRAPPED', 'APP_TERMINATING',
            'REQUEST_RECEIVED', 'REQUEST_HANDLED', 'RESPONSE_SENDING', 'RESPONSE_SENT',
            'PROVIDER_REGISTERING', 'PROVIDER_REGISTERED', 'PROVIDER_BOOTING', 'PROVIDER_BOOTED',
            'EXCEPTION_OCCURRED', 'EXCEPTION_HANDLED',
        ] as $required) {
            $this->assertContains($required, $declared, "the core lifecycle lost {$required}");
        }
    }

    /** Every event name is unique — two constants sharing a value is a typo. */
    public function test_no_two_events_share_a_name(): void
    {
        $names = array_values($this->declaredEvents());

        $this->assertSame(
            array_unique($names),
            $names,
            'two constants resolve to the same event name',
        );
    }

    /**
     * Every event the framework declares, across every layer's catalogue.
     *
     * Not just CoreEvents: the names moved to the layers that raise them, and
     * the promise is the same wherever it is written down.
     *
     * @return array<string, string> CONSTANT => 'event.name'
     */
    private function declaredEvents(): array
    {
        $constants = [];

        foreach ([
            CoreEvents::class,
            \Nitro\Routing\Events\RoutingEvents::class,
            \Nitro\Database\Events\DatabaseEvents::class,
            \Nitro\View\Events\ViewEvents::class,
            \Nitro\Cache\Events\CacheEvents::class,
        ] as $catalogue) {
            foreach ((new ReflectionClass($catalogue))->getConstants() as $constant => $name) {
                $constants[$catalogue . '::' . $constant] = $name;
            }
        }

        $this->assertNotEmpty($constants, 'expected the framework to declare some events');

        return $constants;
    }

    /** @return array<int, string> files that fire this event */
    private function emittersOf(string $constant, string $name): array
    {
        $found = [];

        foreach ($this->sourceFiles() as $relative => $source) {
            if ($relative === 'Events/CoreEvents.php') {
                continue;
            }

            if (preg_match('/CoreEvents::' . preg_quote($constant, '/') . '\b/', $source)
                || preg_match('/[\'"]' . preg_quote($name, '/') . '[\'"]/', $source)) {
                $found[] = $relative;
            }
        }

        return $found;
    }

    /** @return array<string, string> relative path => source */
    private function sourceFiles(): array
    {
        static $sources = null;

        if ($sources !== null) {
            return $sources;
        }

        $root = dirname(__DIR__, 3) . '/src';
        $sources = [];

        $tree = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($tree as $file) {
            if ($file->getExtension() === 'php') {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $sources[$relative] = (string) file_get_contents($file->getPathname());
            }
        }

        return $sources;
    }
}
