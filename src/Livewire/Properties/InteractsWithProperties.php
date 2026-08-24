<?php

namespace Nitro\Livewire\Properties;

use Nitro\Livewire\Features\SupportsFileUploads;
use Nitro\Support\Arr;
use ReflectionObject;
use ReflectionProperty;
use RuntimeException;

/**
 * The public-property machinery, lifted off Component: reading the state that
 * goes into a snapshot, writing a value that came out of one, and the guard
 * that decides what the browser is allowed to touch at all.
 *
 * Applied to {@see \Nitro\Livewire\Component}; it is a trait rather than a
 * collaborator because every method here is about `$this`'s own declared
 * properties, which is exactly what a trait is for.
 */
trait InteractsWithProperties
{
    /** @var array<class-string, true> Classes already validated as fully typed. */
    private static array $typedChecked = [];

    /**
     * The component's public property values — its serializable state.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $state = [];

        foreach ((new ReflectionObject($this))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $state[$property->getName()] = $property->getValue($this);
        }

        return $state;
    }

    /** Assign a public property value (used by hydration and update commits). */
    public function setProperty(string $name, mixed $value): void
    {
        // A wire:model file input sends an upload token (or array of them);
        // promote it to a TemporaryUploadedFile before it lands on the property.
        if (SupportsFileUploads::isUpload($value)) {
            $value = SupportsFileUploads::promote($value);
        }

        // Nested binding: wire:model="form.email" writes into an array property.
        if (str_contains($name, '.')) {
            [$root, $path] = explode('.', $name, 2);
            if ($this->isPublicProperty($root) && is_array($this->{$root})) {
                $array = $this->{$root};
                Arr::set($array, $path, $value);
                $this->{$root} = $array;
            }
            return;
        }

        if ($this->isPublicProperty($name)) {
            $this->{$name} = TypeCoercion::coerce($this, $name, $value);
        }
    }

    /**
     * Whether $name is a PUBLIC property — the only kind the browser may write.
     *
     * A forged `updates` entry / wire:model must never reach protected or private
     * state (server-only invariants, cached flags, service handles). This matches
     * both the properties Nitro exposes in the snapshot (public only) and
     * Livewire's own update guard (getPublicPropertiesDefinedOnSubclass).
     */
    protected function isPublicProperty(string $name): bool
    {
        if (! property_exists($this, $name)) {
            return false;
        }

        return (new ReflectionProperty($this, $name))->isPublic();
    }

    /**
     * Enforce Nitro's typed-property design: every public component property must
     * declare a type, so incoming wire:model values coerce deterministically
     * (see {@see TypeCoercion}). An untyped property is a bug and fails loudly.
     * Validated once per component class.
     */
    public function assertPropertiesAreTyped(): void
    {
        if (isset(self::$typedChecked[static::class])) {
            return;
        }

        foreach ((new ReflectionObject($this))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            if (! $property->hasType()) {
                throw new RuntimeException(sprintf(
                    'Livewire component [%s]: public property [$%s] has no type. Nitro requires '
                    . 'typed public properties (e.g. `public ?string $%s = null;`) so wire:model '
                    . 'values coerce correctly instead of silently mis-casting.',
                    static::class,
                    $property->getName(),
                    $property->getName(),
                ));
            }
        }

        self::$typedChecked[static::class] = true;
    }
}
