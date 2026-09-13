<?php

namespace Nitro\Livewire\Runtime;

use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Livewire\Compilation\SingleFileComponent;
use Nitro\Livewire\Component;
use RuntimeException;

/**
 * The name ↔ class side of the layer: what 'user-profile' means, and how an
 * instance of it comes into existence.
 *
 * Two kinds of component resolve through here — a class-based one (explicitly
 * registered, or found by convention under the configured namespace) and a
 * single-file one (a co-located class + view under resources/views/livewire).
 * Everything else in Runtime/ asks this for a component and never constructs
 * one itself.
 */
class ComponentRegistry
{
    /** @var array<string, class-string> Registered name => component class. */
    protected array $components = [];

    /** Namespace app components are resolved from by convention. */
    protected string $namespace;

    /** The single-file component compiler, built on first use. */
    protected ?SingleFileComponent $singleFile = null;

    public function __construct(protected ContainerInterface $container)
    {
        $this->namespace = rtrim((string) config('livewire.class_namespace', 'App\\Livewire'), '\\') . '\\';
    }

    /** Register a component under an explicit name. */
    public function component(string $name, string $class): void
    {
        $this->components[$name] = $class;
    }

    /**
     * Resolve a component name to its class — an explicit registration first,
     * then by convention (e.g. 'user-profile' → App\Livewire\UserProfile,
     * 'nav.bar' → App\Livewire\Nav\Bar).
     *
     * @return class-string
     */
    public function resolveClass(string $name): string
    {
        $class = $this->resolveClassOrNull($name);

        if ($class === null) {
            throw new RuntimeException("Livewire component [{$name}] not found.");
        }

        return $class;
    }

    /** Resolve a name to a component class, or null if none exists (no throw). */
    public function resolveClassOrNull(string $name): ?string
    {
        if (isset($this->components[$name])) {
            return $this->components[$name];
        }

        // An already-qualified component class resolves to itself. Without
        // this, the convention below prefixes the namespace onto a name that
        // already carries it — App\Livewire\App\Livewire\Basket — so a test
        // naming Basket::class could never find its own component.
        if ($this->isComponentClass($name)) {
            return $name;
        }

        $segments = array_map(
            static fn(string $part): string => str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $part))),
            explode('.', $name)
        );

        $class = $this->namespace . implode('\\', $segments);

        return class_exists($class) ? $class : null;
    }

    /** Whether $name is the class of a component, rather than a component name. */
    protected function isComponentClass(string $name): bool
    {
        return str_contains($name, '\\')
            && class_exists($name)
            && is_subclass_of($name, Component::class);
    }

    /**
     * The dotted, kebab-cased name a class would be known by.
     *
     * App\Livewire\SeatCounter        → seat-counter
     * App\Livewire\Nav\UserBar        → nav.user-bar
     *
     * Anything that is not a class is already a name and passes through.
     */
    protected function conventionalName(string $name): string
    {
        if (! $this->isComponentClass($name)) {
            return $name;
        }

        $relative = str_starts_with($name, $this->namespace)
            ? substr($name, strlen($this->namespace))
            : $name;

        $segments = array_map(
            static fn (string $part): string => strtolower(
                preg_replace('/(?<!^)[A-Z]/', '-$0', $part)
            ),
            explode('\\', $relative)
        );

        return implode('.', $segments);
    }

    /**
     * Instantiate a component by name — a class-based component when one exists,
     * otherwise a single-file component. Assigns a fresh identity; callers that
     * hydrate a snapshot overwrite it with the stored id.
     */
    public function make(string $name): Component
    {
        $class = $this->resolveClassOrNull($name);

        if ($class !== null) {
            /** @var Component $component */
            $component = $this->container->createOrResolve($class);
            $component->assertPropertiesAreTyped();

            // The context name is what the conventional view path is built
            // from, so a component asked for by class gets the same name it
            // would have had by convention. Otherwise it would look for
            // livewire.App\Livewire\SeatCounter and find nothing.
            $component->setContext($this->generateId(), $this->conventionalName($name));

            return $component;
        }

        $sfc = $this->singleFile()->resolve($name);

        if ($sfc !== null) {
            $component = require $sfc['class'];
            if (! $component instanceof Component) {
                throw new RuntimeException("Single-file component [{$name}] must be a Nitro\\Livewire\\Component.");
            }
            $component->assertPropertiesAreTyped();
            $component->setContext($this->generateId(), $name);
            $component->setInlineView($sfc['view']);

            return $component;
        }

        throw new RuntimeException("Livewire component [{$name}] not found (no class or single-file component).");
    }

    /** The single-file component compiler (app SFCs under resources/views/livewire). */
    public function singleFile(): SingleFileComponent
    {
        return $this->singleFile ??= new SingleFileComponent(
            (string) config('livewire.view_path', base_path('resources/views/livewire')),
            storage_path('cache/livewire-sfc')
        );
    }

    /** A short random component instance id. */
    public function generateId(): string
    {
        return substr(bin2hex(random_bytes(12)), 0, 20);
    }
}
