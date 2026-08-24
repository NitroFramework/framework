<?php

namespace Nitro\Livewire\Features;

use Nitro\Livewire\Attributes\Url;
use Nitro\Livewire\Hooks\ComponentHook;
use ReflectionObject;
use ReflectionProperty;

/**
 * #[Url] — a public property kept in sync with the query string, so a filtered
 * or paginated view is shareable and survives a refresh.
 *
 * Two halves meet here: on the way IN the property is seeded from the current
 * request's query string (mount only), and on the way OUT the bindings ride in
 * the snapshot memo so the client can rewrite ?key=value as the property
 * changes (history: true pushes an entry, false replaces).
 */
class SupportsUrlBindings extends ComponentHook
{
    /** Initial render: seed #[Url] properties from the request's query string. */
    public function mount(array $params): void
    {
        $this->initialize();
    }

    /** Publish the bindings so the client can keep the query string in sync. */
    public function dehydrate(array &$memo): void
    {
        if (($bindings = $this->bindings()) !== []) {
            $memo['url'] = $bindings;
        }
    }

    /**
     * The component's #[Url] property bindings — property => { as, history,
     * default }.
     *
     * @return array<string, array{as: string, history: bool, default: mixed}>
     */
    public function bindings(): array
    {
        $bindings = [];

        foreach ((new ReflectionObject($this->component))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $attributes = $property->getAttributes(Url::class);
            if ($attributes === []) {
                continue;
            }

            $url = $attributes[0]->newInstance();
            $name = $property->getName();
            $bindings[$name] = [
                'as'      => $url->as ?? $name,
                'history' => $url->history,
                'default' => $property->hasDefaultValue() ? $property->getDefaultValue() : null,
            ];
        }

        return $bindings;
    }

    /** Seed #[Url] properties from the current request's query string. */
    public function initialize(): void
    {
        $bindings = $this->bindings();
        if ($bindings === []) {
            return;
        }

        $query = (array) request()->query();

        foreach ($bindings as $name => $binding) {
            if (array_key_exists($binding['as'], $query)) {
                $this->component->setProperty($name, $query[$binding['as']]);
            }
        }
    }
}
