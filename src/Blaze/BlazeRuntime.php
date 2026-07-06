<?php

namespace Nitro\Blaze;

use Nitro\View\Component\ComponentAttributeBag;
use Nitro\View\Contracts\ViewEngine;
use Nitro\View\Support\HtmlString;

/**
 * The per-request runtime that compiled templates call as $__blaze. It renders
 * an optimized component by building the same data array Nitro's
 * ComponentRenderer would, then invoking the component's compiled function —
 * skipping the per-render name→path resolution and component-stack bookkeeping.
 *
 * Compiled component functions are bound to this object as $this, so the
 * $this->resolveComponentProps / $this->getAwareData / $this->addLoop calls a
 * component body emits are forwarded (via __call) to the real view engine,
 * keeping output identical to the core renderer.
 */
class BlazeRuntime
{
    public function __construct(protected BlazeManager $manager) {}

    /**
     * Render an optimized component to HTML. Falls back to the core view
     * pipeline if the component turns out not to be compilable.
     */
    public function render(string $name, array $attributes = [], mixed $slot = '', array $slots = []): string
    {
        // Wrap slot content so {{ $slot }} / {{ $slots['x'] }} render raw HTML,
        // exactly as the core renderer does (via HtmlString).
        $slot = $this->toHtml($slot);
        foreach ($slots as $slotName => $slotContent) {
            $slots[$slotName] = $this->toHtml($slotContent);
        }

        $componentData = $attributes;
        $componentData['slot'] = $slot;
        $componentData['slots'] = $slots;

        // Named slots are also exposed as top-level variables, like core.
        foreach ($slots as $slotName => $slotContent) {
            $componentData[$slotName] = $slotContent;
        }

        $data = $componentData + [
            '__componentData' => $componentData,
            'slot'            => $slot,
            'slots'           => $slots,
            'attributes'      => new ComponentAttributeBag($this->stripReserved($componentData, $slots)),
        ];

        $fn = $this->manager->functionFor($name, $this);

        if ($fn === null) {
            return $this->engine()->renderPartial('components.' . str_replace(':', '.', $name), $data);
        }

        return $fn($data);
    }

    /** Wrap raw slot HTML so it renders untouched in {{ }} echoes. */
    protected function toHtml(mixed $value): HtmlString
    {
        return $value instanceof HtmlString ? $value : new HtmlString((string) $value);
    }

    /** Strip reserved + named-slot keys so only real HTML attributes remain. */
    protected function stripReserved(array $data, array $slots): array
    {
        $reserved = ['slot' => 1, 'slots' => 1, '__componentData' => 1] + array_flip(array_keys($slots));

        return array_diff_key($data, $reserved);
    }

    /**
     * Forward the helper calls a compiled component body makes on $this
     * (resolveComponentProps, getAwareData, addLoop, …) to the real view engine
     * so props, aware data and loop variables behave exactly as in core.
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->engine()->{$method}(...$arguments);
    }

    protected function engine(): ViewEngine
    {
        return app(ViewEngine::class);
    }
}
