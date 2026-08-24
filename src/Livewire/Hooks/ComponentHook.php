<?php

namespace Nitro\Livewire\Hooks;

use Nitro\Livewire\Component;

/**
 * A framework feature that attaches to a component's lifecycle.
 *
 * One hook instance is created PER COMPONENT (see {@see ComponentHooks}), so a
 * hook may hold that component's feature state as ordinary properties — the
 * validation error bag, the island render mode, the computed-property memo —
 * instead of that state living on Component itself.
 *
 * Every method is optional; the default implementations do nothing, so a
 * feature overrides only the moments it cares about:
 *
 *   boot()       — the component exists and its state is loaded (both on the
 *                  initial mount and on every subsequent commit).
 *   mount()      — INITIAL render only, right after the user's own mount().
 *   hydrate()    — a commit only, right after the snapshot was restored.
 *   render()     — after the view produced HTML; may return replacement HTML.
 *   dehydrate()  — contribute to the snapshot memo on the way out.
 *   destroy()    — the request is done with this component.
 */
abstract class ComponentHook
{
    /** The component this hook instance is bound to. */
    protected Component $component;

    /** Bind the hook to its component (called once, by ComponentHooks). */
    public function setComponent(Component $component): void
    {
        $this->component = $component;
    }

    /** The component this hook belongs to. */
    public function component(): Component
    {
        return $this->component;
    }

    /** The component exists and its public state is loaded. */
    public function boot(): void {}

    /** Initial render only — runs after the component's own mount($params). */
    public function mount(array $params): void {}

    /** A commit — runs after the snapshot's state was restored onto the component. */
    public function hydrate(array $memo): void {}

    /** Transform (or just observe) the rendered HTML. Return it unchanged to pass through. */
    public function render(string $html): string
    {
        return $html;
    }

    /** Contribute this feature's entries to the outgoing snapshot memo. */
    public function dehydrate(array &$memo): void {}

    /** The request is finished with this component. */
    public function destroy(): void {}
}
