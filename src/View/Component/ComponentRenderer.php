<?php

namespace Nitro\View\Component;

use Closure;
use Nitro\View\Contracts\ComponentEngine;
use Nitro\View\Contracts\Engine;
use Nitro\View\Support\HtmlString;
use ReflectionClass;

/**
 * Renders view components: resolves the tag, builds the class behind it when
 * there is one, and hands the result to the view engine.
 *
 * Reflection and class existence are cached per class for the process.
 */
class ComponentRenderer implements ComponentEngine
{
    /**
     * Components opened and not yet closed, innermost last.
     *
     * @var array<int, array{name: string, attributes: array<string, mixed>, slots: array<string, HtmlString>, data: array<string, mixed>}>
     */
    protected array $componentStack = [];

    /**
     * Named slots opened and not yet closed, innermost last.
     *
     * @var array<int, string>
     */
    protected array $namedSlotStack = [];

    /**
     * The data of the component currently rendering, for `@aware` to read.
     *
     * @var array<string, mixed>
     */
    protected array $currentComponentData = [];

    /**
     * The view engine, resolved on first use.
     *
     * Taken as a factory because the engine and this renderer each need the
     * other to construct.
     */
    private ?Engine $resolvedRenderer = null;

    /**
     * Keys carrying slot content or internal state, which must never reach an
     * attribute bag. Held flipped for a single array_diff_key.
     */
    private const RESERVED_ATTR_KEYS = [
        'slot'            => 0,
        'slots'           => 0,
        '__componentData' => 0,
    ];

    /**
     * Constructor parameters per component class, null when there is none.
     *
     * @var array<class-string, array<int, array{name: string, hasDefault: bool, default: mixed, nullable: bool}>|null>
     */
    private static array $ctorMetaCache = [];

    /**
     * The class backing each tag name, or `''` when it has none.
     *
     * @var array<string, string>
     */
    private static array $classNameCache = [];

    /**
     * @param Closure $rendererFactory Returns the view engine, called once.
     */
    public function __construct(
        private Closure $rendererFactory,
    ) {
    }

    /**
     * The view engine, resolved on first use and kept.
     */
    private function renderer(): Engine
    {
        return $this->resolvedRenderer ??= ($this->rendererFactory)();
    }

    // ─── Opening and closing components ───────────────────

    /**
     * Render a component with no body, such as `<x-alert />`.
     *
     * @param array<string, mixed> $attributes
     * @param string               $slot Content for the default slot.
     */
    public function renderSelfClosing(string $name, array $attributes = [], string $slot = ''): void
    {
        $componentData                    = $attributes;
        $componentData['slot']            = new HtmlString($slot);
        $componentData['slots']           = [];
        $componentData['__componentData'] = $componentData;

        echo $this->renderComponentView($this->resolveComponentData($name, $componentData));
    }

    /**
     * Begin capturing a component's body as its default slot.
     *
     * @param array<string, mixed> $attributes
     */
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

    /**
     * Finish the innermost component and return its rendered markup.
     *
     * Named slots are exposed both under `slots` and at the top level.
     */
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

        return $this->renderComponentView($this->resolveComponentData($info['name'], $componentData));
    }

    /**
     * Begin capturing a named slot, such as `<x-slot:title>`.
     */
    public function startNamedSlot(string $name): void
    {
        $this->namedSlotStack[] = $name;

        ob_start();
    }

    /**
     * Finish the innermost named slot and attach it to its component.
     */
    public function endNamedSlot(): void
    {
        $content = new HtmlString(trim(ob_get_clean()));
        $name    = array_pop($this->namedSlotStack);

        if (! empty($this->componentStack)) {
            $last = count($this->componentStack) - 1;
            $this->componentStack[$last]['slots'][$name] = $content;
        }
    }

    // ─── Resolving a tag ──────────────────────────────────

    /**
     * Work out which template renders this tag, and with what data.
     *
     * The view data is built in one pass rather than a chain of merges: what
     * the tag supplied, then the class's own data over it, then the slot and
     * attribute triplet pinned last so a class cannot overwrite it.
     *
     * @param  array<string, mixed> $componentData
     * @return array{view: string, data: array<string, mixed>}
     */
    protected function resolveComponentData(string $name, array $componentData): array
    {
        $className = self::$classNameCache[$name]
            ??= $this->guessClassNameOrEmpty($name);

        if ($className !== '') {
            $component = $this->buildComponentInstance($className, $componentData);

            $slotData = $componentData['slots'] ?? [];

            $component->slot       = $componentData['slot'];
            $component->slots      = $slotData;
            $component->attributes = new ComponentAttributeBag(
                $this->stripReservedAndSlotKeys($componentData, $slotData)
            );

            $viewData = $componentData;

            foreach ($component->data() as $dataKey => $value) {
                $viewData[$dataKey] = $value;
            }

            foreach ($component->with() as $dataKey => $value) {
                $viewData[$dataKey] = $value;
            }

            $viewData['slot']            = $component->slot;
            $viewData['slots']           = $component->slots;
            $viewData['attributes']      = $component->attributes;
            $viewData['__componentData'] = $viewData;

            return ['view' => $component->render(), 'data' => $viewData];
        }

        $remaining = $this->stripReservedAndSlotKeys($componentData, $componentData['slots'] ?? []);

        return [
            'view' => 'components.' . str_replace([':'], '.', $name),
            'data' => $componentData + [
                '__componentData' => $componentData,
                'slot'            => $componentData['slot'],
                'slots'           => $componentData['slots'],
                'attributes'      => new ComponentAttributeBag($remaining),
            ],
        ];
    }

    /**
     * Render a resolved component as a partial, so it inherits no layout of
     * its own.
     *
     * @param array{view: string, data: array<string, mixed>} $resolved
     */
    protected function renderComponentView(array $resolved): string
    {
        return $this->renderer()->renderPartial($resolved['view'], $resolved['data']);
    }

    /**
     * The class backing a tag, or an empty string when the tag has none.
     */
    protected function guessClassNameOrEmpty(string $name): string
    {
        $parts = explode('.', $name);

        $className = 'App\\View\\Components\\' . implode('\\', array_map(
            static fn (string $part): string => str_replace('-', '', ucwords($part, '-')),
            $parts
        ));

        if (class_exists($className) && is_subclass_of($className, Component::class)) {
            return $className;
        }

        return '';
    }

    /**
     * The class backing a tag.
     *
     * @deprecated Use {@see guessClassNameOrEmpty()}. Kept because subclasses
     *             may override it.
     */
    protected function guessComponentClass(string $name): string
    {
        return $this->guessClassNameOrEmpty($name);
    }

    /**
     * Construct a component, mapping the tag's attributes onto its constructor.
     *
     * A parameter with no matching attribute, default or null is left out, so
     * PHP raises a missing-argument error naming it.
     *
     * @param array<string, mixed> $componentData
     */
    protected function buildComponentInstance(string $className, array $componentData): Component
    {
        if (! array_key_exists($className, self::$ctorMetaCache)) {
            $constructor = (new ReflectionClass($className))->getConstructor();

            if ($constructor === null) {
                self::$ctorMetaCache[$className] = null;
            } else {
                $meta = [];

                foreach ($constructor->getParameters() as $parameter) {
                    $meta[] = [
                        'name'       => $parameter->getName(),
                        'hasDefault' => $parameter->isDefaultValueAvailable(),
                        'default'    => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
                        'nullable'   => $parameter->allowsNull(),
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

        foreach ($meta as $parameter) {
            $name = $parameter['name'];

            if (array_key_exists($name, $componentData)) {
                $args[$name] = $componentData[$name];
            } elseif ($parameter['hasDefault']) {
                $args[$name] = $parameter['default'];
            } elseif ($parameter['nullable']) {
                $args[$name] = null;
            }
        }

        return new $className(...$args);
    }

    /**
     * Remove the reserved keys and named slots from an attribute bag.
     *
     * @param  array<string, mixed> $componentData
     * @param  array<string, mixed> $slots
     * @return array<string, mixed>
     */
    private function stripReservedAndSlotKeys(array $componentData, array $slots): array
    {
        if (empty($slots)) {
            return array_diff_key($componentData, self::RESERVED_ATTR_KEYS);
        }

        $exclude = self::RESERVED_ATTR_KEYS;

        foreach ($slots as $name => $_) {
            $exclude[$name] = 0;
        }

        return array_diff_key($componentData, $exclude);
    }

    // ─── What compiled @aware and @props call ─────────────

    /**
     * Values an enclosing component passed, for `@aware` to inherit.
     *
     * Walks outward from the innermost component, so the nearest ancestor that
     * supplied a key wins.
     *
     * @param  array<int, string> $keys
     * @return array<string, mixed>
     */
    public function getAwareData(array $keys): array
    {
        $result = [];

        foreach (array_reverse($this->componentStack) as $frame) {
            foreach ($keys as $key) {
                if (! isset($result[$key]) && isset($frame['attributes'][$key])) {
                    $result[$key] = $frame['attributes'][$key];
                }
            }
        }

        return $result;
    }

    /**
     * Split what a tag was given into declared props and leftover attributes.
     *
     * @param  array<int|string, mixed> $propDefaults An integer key means a prop with no default.
     * @param  array<string, mixed>     $componentData
     * @return array{0: array<string, mixed>, 1: ComponentAttributeBag}
     */
    public function resolveComponentProps(array $propDefaults, array $componentData): array
    {
        $props    = [];
        $propKeys = [];

        foreach ($propDefaults as $key => $default) {
            if (is_int($key)) {
                $props[$default] = $componentData[$default] ?? null;
                $propKeys[]      = $default;
            } else {
                $props[$key] = $componentData[$key] ?? $default;
                $propKeys[]  = $key;
            }
        }

        $namedSlotKeys = array_keys($componentData['slots'] ?? []);
        $reserved      = array_merge($propKeys, ['slot', 'slots', '__componentData'], $namedSlotKeys);
        $remaining     = array_diff_key($componentData, array_flip($reserved));

        return [$props, new ComponentAttributeBag($remaining)];
    }

    /**
     * Replace the data visible to the component currently rendering.
     *
     * @param array<string, mixed> $data
     */
    public function setComponentData(array $data): void
    {
        $this->currentComponentData = $data;
    }
}
