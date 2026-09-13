<?php

namespace Nitro\Livewire\Testing;

use Nitro\Livewire\Component;
use Nitro\Livewire\Runtime\LivewireManager;
use PHPUnit\Framework\Assert;

/**
 * A mounted component, driven the way the browser drives one.
 *
 * set() and call() do not poke at the object. They build the same commit
 * payload the client posts and push it through the real update pipeline, so
 * every step the browser would trigger actually happens: the state is
 * dehydrated to a snapshot, checksum-verified, hydrated back, #[Locked] is
 * enforced, updating/updated hooks fire, and the component re-renders.
 *
 * That matters because the bugs worth catching live in that round trip. A
 * property holding something the synthesizers cannot carry, a #[Locked] guard
 * that does not hold, a computed property that is not invalidated after a
 * write — none of them are visible to a test that calls $component->save()
 * directly, and all of them break the page.
 *
 * What it does NOT do is run middleware: the pipeline is invoked in-process,
 * not through /livewire/update. Anything a component reads out of ambient
 * request state has to be set up by the test, and the middleware that would
 * have set it up in a browser is proven separately — see
 * LivewireManager::addPersistentMiddleware().
 */
class TestableComponent
{
    /** The most recent snapshot, as the client would hold it. */
    protected array $snapshot;

    /** The most recent rendered HTML. */
    protected string $html;

    /** Effects from the last commit: dispatches, redirect, and so on. */
    protected array $effects = [];

    /** The live instance. Read for assertions; never the thing under test. */
    protected Component $component;

    public function __construct(
        protected LivewireManager $manager,
        string $name,
        array $params = [],
    ) {
        $this->component = $this->manager->registry()->make($name);

        $this->html = $this->manager->mounter()->renderNew($this->component, $params);
        $this->snapshot = $this->manager->snapshot($this->component);
    }

    // ─── Driving it ───────────────────────────────────────

    /** Set one property, or several, as wire:model would. */
    public function set(string|array $property, mixed $value = null): static
    {
        $updates = is_array($property) ? $property : [$property => $value];

        return $this->commit($updates, []);
    }

    /** Call an action, as wire:click would. */
    public function call(string $method, mixed ...$params): static
    {
        return $this->commit([], [['method' => $method, 'params' => $params]]);
    }

    /** Set properties and then call an action, in one commit. */
    public function setThenCall(array $updates, string $method, mixed ...$params): static
    {
        return $this->commit($updates, [['method' => $method, 'params' => $params]]);
    }

    /**
     * Push one commit through the pipeline and adopt what comes back.
     *
     * The returned snapshot replaces ours, exactly as the client replaces its
     * own — so a second call() starts from the state the first one left, rather
     * than from the mount.
     */
    protected function commit(array $updates, array $calls): static
    {
        $result = $this->manager->update(['components' => [[
            'snapshot' => $this->snapshot,
            'updates' => $updates,
            'calls' => $calls,
        ]]]);

        $commit = $result['components'][0] ?? [];

        $this->snapshot = $commit['snapshot'] ?? $this->snapshot;
        $this->effects = $commit['effects'] ?? [];
        $this->html = (string) ($this->effects['html'] ?? '');

        // Re-hydrate so get() and assertSet() read post-commit state rather
        // than the instance we mounted, which the pipeline never touched.
        $this->component = $this->manager->fromSnapshot($this->snapshot);

        return $this;
    }

    // ─── Reading it ───────────────────────────────────────

    public function html(): string
    {
        return $this->html;
    }

    public function effects(): array
    {
        return $this->effects;
    }

    public function instance(): Component
    {
        return $this->component;
    }

    /** A property's current value, after the last commit. */
    public function get(string $property): mixed
    {
        return $this->snapshot['data'][$property] ?? $this->component->{$property} ?? null;
    }

    // ─── Assertions ───────────────────────────────────────

    public function assertSet(string $property, mixed $expected): static
    {
        Assert::assertEquals(
            $expected,
            $this->get($property),
            "Property [{$property}] is not what was expected."
        );

        return $this;
    }

    public function assertSee(string $value, bool $escaped = true): static
    {
        $needle = $escaped ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;

        Assert::assertStringContainsString($needle, $this->html, "Did not see [{$value}] in the rendered component.");

        return $this;
    }

    public function assertDontSee(string $value, bool $escaped = true): static
    {
        $needle = $escaped ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;

        Assert::assertStringNotContainsString($needle, $this->html, "Unexpectedly saw [{$value}].");

        return $this;
    }

    /** An event the component dispatched to the browser during the last commit. */
    public function assertDispatched(string $event): static
    {
        $names = array_map(
            fn ($dispatch) => is_array($dispatch) ? ($dispatch['event'] ?? null) : $dispatch,
            (array) ($this->effects['dispatches'] ?? [])
        );

        Assert::assertContains($event, $names, "The component did not dispatch [{$event}].");

        return $this;
    }

    public function assertNotDispatched(string $event): static
    {
        $names = array_map(
            fn ($dispatch) => is_array($dispatch) ? ($dispatch['event'] ?? null) : $dispatch,
            (array) ($this->effects['dispatches'] ?? [])
        );

        Assert::assertNotContains($event, $names, "The component unexpectedly dispatched [{$event}].");

        return $this;
    }

    public function assertRedirect(?string $to = null): static
    {
        $redirect = $this->effects['redirect'] ?? null;

        Assert::assertNotNull($redirect, 'The component did not redirect.');

        if ($to !== null) {
            Assert::assertSame($to, $redirect, 'The component redirected somewhere else.');
        }

        return $this;
    }

    /** A validation error is present for a field. */
    public function assertHasErrors(string|array $fields): static
    {
        $errors = $this->errorKeys();

        foreach ((array) $fields as $field) {
            Assert::assertContains($field, $errors, "There is no validation error for [{$field}].");
        }

        return $this;
    }

    public function assertHasNoErrors(string|array|null $fields = null): static
    {
        $errors = $this->errorKeys();

        if ($fields === null) {
            Assert::assertSame([], $errors, 'The component has validation errors.');

            return $this;
        }

        foreach ((array) $fields as $field) {
            Assert::assertNotContains($field, $errors, "There is an unexpected validation error for [{$field}].");
        }

        return $this;
    }

    /** @return array<int, string> */
    protected function errorKeys(): array
    {
        return array_keys($this->component->errors()->all());
    }
}
