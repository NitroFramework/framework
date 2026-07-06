<?php

namespace Nitro\View\Component;

use Nitro\View\Contracts\ViewEngine;
use Nitro\View\Contracts\ComponentEngine;
use Nitro\View\Support\HtmlString;
use Nitro\View\Component\Component;
use Nitro\View\Component\ComponentAttributeBag;

/**
 * Renders view components, resolving the class and merging attributes and slots.
 */
class ComponentRenderer implements ComponentEngine
{
    protected array $componentStack = [];
    protected array $namedSlotStack = [];
    protected array $currentComponentData = [];

    private ?ViewEngine $resolvedRenderer = null;

    /**
     * Reserved keys that should never appear inside an attribute bag.
     * Stored as a flipped map so attribute-bag construction can do an
     * array_diff_key against this constant set instead of building a
     * fresh flipped array on every component render.
     */
    private const RESERVED_ATTR_KEYS = [
        'slot'            => 0,
        'slots'           => 0,
        '__componentData' => 0,
    ];

    /**
     * Per-class cache of constructor parameter metadata. Reflection runs
     * exactly once per component class — every subsequent render of
     * <x-card> reuses the cached metadata. The class cache survives the
     * entire request and (in worker mode) the entire process.
     *
     * Entry shape: null when the class has no constructor; otherwise an
     * array of {name, hasDefault, default, nullable} for each parameter.
     */
    private static array $ctorMetaCache = [];

    /**
     * Per-class cache of the resolved component class (or '' when no
     * class exists for the given tag). class_exists() is comparatively
     * expensive — short-circuit it on second use.
     */
    private static array $classNameCache = [];

    public function __construct(
        private \Closure $rendererFactory,
    ) {}

    private function renderer(): ViewEngine
    {
        if ($this->resolvedRenderer === null) {
            $this->resolvedRenderer = ($this->rendererFactory)();
        }
        return $this->resolvedRenderer;
    }

    // Self-closing <x-alert />
    public function renderSelfClosing(string $name, array $attributes = [], string $slot = ''): void
    {
        $componentData                    = $attributes;
        $componentData['slot']            = new HtmlString($slot);
        $componentData['slots']           = [];
        $componentData['__componentData'] = $componentData;

        $resolved = $this->resolveComponentData($name, $componentData);
        echo $this->renderComponentView($resolved);
    }

    // Opening <x-alert>
    public function start(string $name, array $attributes = []): void
    {
        $this->componentStack[] = [
            'name'       => $name,
            'attributes' => $attributes,
            'slots'      => [],
            'data'       => $this->currentComponentData,
        ];

        ob_start();
    }

    // Closing </x-alert>
    public function end(): string
    {
        $slotHtml = ob_get_clean();
        $info     = array_pop($this->componentStack);

        $componentData                    = $info['attributes'];
        $componentData['slot']            = new HtmlString(trim($slotHtml));
        $componentData['slots']           = $info['slots'];
        $componentData['__componentData'] = $componentData;

        foreach ($info['slots'] as $slotName => $slotContent) {
            $componentData[$slotName] = $slotContent;
        }

        $resolved = $this->resolveComponentData($info['name'], $componentData);
        return $this->renderComponentView($resolved);
    }

    // <x-slot:title>
    public function startNamedSlot(string $name): void
    {
        $this->namedSlotStack[] = $name;
        ob_start();
    }

    // </x-slot:title>
    public function endNamedSlot(): void
    {
        $content = new HtmlString(trim(ob_get_clean()));
        $name    = array_pop($this->namedSlotStack);

        if (!empty($this->componentStack)) {
            $last = count($this->componentStack) - 1;
            $this->componentStack[$last]['slots'][$name] = $content;
        }
    }

    /**
     * Resolve a tag to either a class-backed component (and merge its
     * data) or a plain template. Three perf shifts over the previous
     * version:
     *   - class_exists() result is cached per tag name;
     *   - the constructor-parameter list is reflected ONCE per class;
     *   - the final view-data array is built in a single foreach pass
     *     instead of three chained array_merge() calls.
     */
    protected function resolveComponentData(string $name, array $componentData): array
    {
        $className = self::$classNameCache[$name]
            ?? (self::$classNameCache[$name] = $this->guessClassNameOrEmpty($name));

        // Class-backed component path.
        if ($className !== '') {
            $component = $this->buildComponentInstance($className, $componentData);

            $slotData  = $componentData['slots'] ?? [];
            $component->slot       = $componentData['slot'];
            $component->slots      = $slotData;
            $component->attributes = new ComponentAttributeBag(
                $this->stripReservedAndSlotKeys($componentData, $slotData)
            );

            // Build view data in one pass: start from componentData, layer
            // class-supplied data() and with() on top, then pin the final
            // slot/slots/attributes triplet. Avoids the 3-merge chain the
            // old code used.
            $viewData = $componentData;
            foreach ($component->data() as $k => $v) { $viewData[$k] = $v; }
            foreach ($component->with() as $k => $v) { $viewData[$k] = $v; }

            $viewData['slot']       = $component->slot;
            $viewData['slots']      = $component->slots;
            $viewData['attributes'] = $component->attributes;
            $viewData['__componentData'] = $viewData;

            return ['view' => $component->render(), 'data' => $viewData];
        }

        // Plain-template component path.
        $remaining = $this->stripReservedAndSlotKeys($componentData, $componentData['slots'] ?? []);

        return [
            'view' => 'components.' . str_replace([':' ], '.', $name),
            'data' => $componentData + [
                '__componentData' => $componentData,
                'slot'            => $componentData['slot'],
                'slots'           => $componentData['slots'],
                'attributes'      => new ComponentAttributeBag($remaining),
            ],
        ];
    }

    protected function renderComponentView(array $resolved): string
    {
        return $this->renderer()->renderPartial($resolved['view'], $resolved['data']);
    }

    /**
     * Same convention as before — `card.button` → App\View\Components\Card\Button —
     * but returns '' (empty string) when no such class exists so the caller
     * can cache "no class for this tag" and skip class_exists on repeat use.
     */
    protected function guessClassNameOrEmpty(string $name): string
    {
        $parts = explode('.', $name);
        $className = 'App\\View\\Components\\' . implode('\\', array_map(
            static fn(string $part) => str_replace('-', '', ucwords($part, '-')),
            $parts
        ));

        if (class_exists($className) && is_subclass_of($className, Component::class)) {
            return $className;
        }
        return '';
    }

    /** Kept for backward compatibility with subclasses that override it. */
    protected function guessComponentClass(string $name): string
    {
        return $this->guessClassNameOrEmpty($name);
    }

    /**
     * Instantiate a component class, mapping $componentData onto the
     * constructor parameters. Reflection runs once per class — the
     * parameter list is cached in $ctorMetaCache.
     */
    protected function buildComponentInstance(string $className, array $componentData): Component
    {
        if (!array_key_exists($className, self::$ctorMetaCache)) {
            $ref = new \ReflectionClass($className);
            $ctor = $ref->getConstructor();
            if ($ctor === null) {
                self::$ctorMetaCache[$className] = null;
            } else {
                $meta = [];
                foreach ($ctor->getParameters() as $param) {
                    $meta[] = [
                        'name'       => $param->getName(),
                        'hasDefault' => $param->isDefaultValueAvailable(),
                        'default'    => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
                        'nullable'   => $param->allowsNull(),
                    ];
                }
                self::$ctorMetaCache[$className] = $meta;
            }
        }

        $meta = self::$ctorMetaCache[$className];
        if ($meta === null) {
            return new $className();
        }

        $args = [];
        foreach ($meta as $p) {
            $n = $p['name'];
            if (array_key_exists($n, $componentData)) {
                $args[$n] = $componentData[$n];
            } elseif ($p['hasDefault']) {
                $args[$n] = $p['default'];
            } elseif ($p['nullable']) {
                $args[$n] = null;
            }
        }

        return new $className(...$args);
    }

    /**
     * Strip both the reserved attribute-bag keys (slot/slots/__componentData)
     * AND the named-slot keys (each named slot also lives at the top level
     * of $componentData as `$title`, `$footer`, etc., and shouldn't leak
     * into the attribute bag).
     */
    private function stripReservedAndSlotKeys(array $componentData, array $slots): array
    {
        if (empty($slots)) {
            return array_diff_key($componentData, self::RESERVED_ATTR_KEYS);
        }
        // Slot names typically number 1–3; building a tiny flip is cheaper
        // than constructing a fresh full-set diff key for each invocation.
        $exclude = self::RESERVED_ATTR_KEYS;
        foreach ($slots as $name => $_) {
            $exclude[$name] = 0;
        }
        return array_diff_key($componentData, $exclude);
    }

    // @aware / @props
    public function getAwareData(array $keys): array
    {
        $result = [];

        foreach (array_reverse($this->componentStack) as $frame) {
            foreach ($keys as $key) {
                if (!isset($result[$key]) && isset($frame['attributes'][$key])) {
                    $result[$key] = $frame['attributes'][$key];
                }
            }
        }

        return $result;
    }
    public function resolveComponentProps(array $propDefaults, array $componentData): array
    {
        $props    = [];
        $propKeys = [];

        foreach ($propDefaults as $key => $default) {
            if (is_int($key)) {
                $props[$default] = $componentData[$default] ?? null;
                $propKeys[]      = $default;
            } else {
                $props[$key]     = $componentData[$key] ?? $default;
                $propKeys[]      = $key;
            }
        }

        $namedSlotKeys = array_keys($componentData['slots'] ?? []);
        $reserved      = array_merge($propKeys, ['slot', 'slots', '__componentData'], $namedSlotKeys);
        $remaining     = array_diff_key($componentData, array_flip($reserved));

        return [$props, new ComponentAttributeBag($remaining)];
    }
    public function setComponentData(array $data): void
    {
        $this->currentComponentData = $data;
    }
}
