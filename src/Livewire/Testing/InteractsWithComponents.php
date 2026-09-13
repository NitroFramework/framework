<?php

namespace Nitro\Livewire\Testing;

use Nitro\Livewire\Runtime\LivewireManager;

/**
 * Adds Livewire component testing to a test case.
 *
 *     $this->livewire(Basket::class)
 *         ->set('quantity', 3)
 *         ->call('add')
 *         ->assertSet('total', 7245)
 *         ->assertSee('Your basket');
 *
 * Use it alongside Nitro\Testing\TestCase, which provides the application the
 * component is resolved out of.
 */
trait InteractsWithComponents
{
    /**
     * Mount a component for testing.
     *
     * @param  string  $name  A registered component name, or its class.
     */
    public function livewire(string $name, array $params = []): TestableComponent
    {
        return new TestableComponent($this->livewireManager(), $name, $params);
    }

    protected function livewireManager(): LivewireManager
    {
        // Through the container so the component is built with the same
        // bindings the application would give it.
        return method_exists($this, 'make')
            ? $this->make(LivewireManager::class)
            : \app(LivewireManager::class);
    }
}
