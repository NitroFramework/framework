<?php

namespace Nitro\Livewire\Hooks;

use Nitro\Livewire\Component;
use Nitro\Livewire\Features\SupportsLockedProperties;

/**
 * The second hook family: PROPERTY hooks. Where {@see ComponentHooks} fans out
 * nullary lifecycle moments, this one is per-update — it owns everything that
 * happens when the browser writes a property:
 *
 *   1. the #[Locked] guard (a locked property is never writable from the client),
 *   2. the hook-name derivation, including nested keys,
 *   3. the updating/updated fan-out, and the transform an updating hook may apply.
 *
 * Hook names follow Livewire's convention:
 *
 *   updates: ['title' => 'x']       → updating('title', 'x'), updatingTitle('x')
 *   updates: ['first_name' => 'x']  → updatingFirstName('x')
 *   updates: ['form.email' => 'x']  → updatingForm('x', 'email')
 *
 * The nested form is the fix for the derivation that used to studly the WHOLE
 * key: 'form.email' became 'Form.email', so `updatingForm.email` was looked up
 * and — never being a legal method name — no hook on a nested binding ever
 * fired. The root property is now studlied and the remaining path is handed to
 * the hook as its second argument, exactly as Livewire does it.
 *
 * TRANSFORM: an `updating` hook that RETURNS a non-null value replaces the value
 * that is written (and the one passed to the `updated` hooks) — the seam for
 * normalising input (trim, upper-case, strip a currency symbol) in one place:
 *
 *     public function updatingEmail(string $value): string
 *     {
 *         return strtolower(trim($value));
 *     }
 *
 * An `updating` hook that returns null (the common `: void` case) leaves the
 * value alone, so existing hooks are unaffected.
 */
class PropertyHooks
{
    /**
     * Apply a commit's `updates` map to the component, firing the hooks around
     * each write.
     *
     * @param array<string, mixed> $updates
     */
    public function apply(Component $component, array $updates): void
    {
        foreach ($updates as $key => $value) {
            $this->update($component, (string) $key, $value);
        }
    }

    /** Apply one property update: guard, updating hooks, write, updated hooks. */
    public function update(Component $component, string $key, mixed $value): void
    {
        // #[Locked] properties may not be changed from the browser — reject a
        // wire:model update or a forged `updates` entry that targets one.
        SupportsLockedProperties::assertNotLocked($component, $key);

        [$studly, $rest] = $this->hookName($key);

        $value = $this->fire($component, 'updating', $key, $studly, $rest, $value);

        $component->setProperty($key, $value);

        $this->fire($component, 'updated', $key, $studly, $rest, $value);
    }

    /**
     * Fire the generic hook and the per-property hook for one phase, letting
     * either transform the value it is handed.
     */
    protected function fire(
        Component $component,
        string $phase,
        string $key,
        string $studly,
        ?string $rest,
        mixed $value
    ): mixed {
        $value = $this->transform($value, UserHooks::call($component, $phase, $key, $value));

        $value = $rest === null
            ? $this->transform($value, UserHooks::call($component, $phase . $studly, $value))
            : $this->transform($value, UserHooks::call($component, $phase . $studly, $value, $rest));

        return $value;
    }

    /** A hook's non-null return value replaces the value being written. */
    protected function transform(mixed $value, mixed $returned): mixed
    {
        return $returned ?? $value;
    }

    /**
     * Split an update key into the studly hook suffix and the remaining path.
     *
     * 'title'      → ['Title', null]
     * 'first_name' → ['FirstName', null]
     * 'form.email' → ['Form', 'email']
     *
     * @return array{0: string, 1: string|null}
     */
    public function hookName(string $key): array
    {
        $rest = null;
        $root = $key;

        if (str_contains($key, '.')) {
            [$root, $rest] = explode('.', $key, 2);
        }

        return [str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $root))), $rest];
    }
}
