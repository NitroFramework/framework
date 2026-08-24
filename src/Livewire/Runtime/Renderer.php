<?php

namespace Nitro\Livewire\Runtime;

use Nitro\Livewire\Component;
use Nitro\Livewire\Hooks\UserHooks;

/**
 * Turning a component into HTML, and shaping that HTML for the wire: the
 * rendering/rendered lifecycle, the wire:id + wire:snapshot attributes the
 * client hydrates from, and pulling a single wire:region block out of a
 * rendered document.
 */
class Renderer
{
    /**
     * Render the component, firing the rendering/rendered lifecycle hooks around
     * it and letting the feature hooks transform the result.
     */
    public function render(Component $component): string
    {
        UserHooks::call($component, 'rendering');

        $html = $component->hooks()->render($component->render());

        UserHooks::call($component, 'rendered', $html);

        return $html;
    }

    /**
     * Inject wire:id + wire:snapshot onto the component's single root element so
     * the client can hydrate and re-render it.
     */
    public function wrapRoot(string $html, string $id, array $snapshot): string
    {
        $attrs = ' wire:id="' . $id . '"'
            . ' wire:snapshot="' . htmlspecialchars(json_encode($snapshot), ENT_QUOTES) . '"';

        return preg_replace_callback(
            '/<[a-zA-Z][a-zA-Z0-9-]*/',
            static fn(array $matches): string => $matches[0] . $attrs,
            $html,
            1
        ) ?? $html;
    }

    /**
     * Pull a single <div wire:region="name">…</div> block out of rendered HTML,
     * depth-matching nested <div> tags. Returns null if the region isn't found.
     * Self-contained to the Livewire layer — unrelated to Nitro's @fragment.
     */
    public function extractRegion(string $html, string $name): ?string
    {
        $marker = 'wire:region="' . $name . '"';
        $markerPos = strpos($html, $marker);
        if ($markerPos === false) {
            return null;
        }

        $start = strrpos(substr($html, 0, $markerPos), '<div');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $i = $start;
        $len = strlen($html);

        while ($i < $len) {
            $open = strpos($html, '<div', $i);
            $close = strpos($html, '</div>', $i);
            if ($close === false) {
                return null;
            }

            if ($open !== false && $open < $close) {
                $depth++;
                $i = $open + 4;
            } else {
                $depth--;
                $i = $close + 6;
                if ($depth === 0) {
                    return substr($html, $start, $i - $start);
                }
            }
        }

        return null;
    }
}
