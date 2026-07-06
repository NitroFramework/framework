<?php

namespace Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use Nitro\View\Component\Component;
use Nitro\View\Component\ComponentRenderer;
use Nitro\View\Contracts\ViewEngine;

/**
 * The component renderer used to call ReflectionClass on every <x-foo>
 * invocation. We now cache constructor metadata per className. These
 * tests pin both the cache write and the correctness of the cached
 * dispatch (defaults, nullable, present/absent data).
 */
class ComponentReflectionCacheTest extends TestCase
{
    private function renderer(): ComponentRenderer
    {
        // We don't render anything — just exercise buildComponentInstance
        // and the cache. A stub ViewEngine factory keeps the constructor
        // happy without spinning up the whole view stack.
        return new ComponentRenderer(fn() => $this->createMock(ViewEngine::class));
    }

    public function test_constructor_metadata_is_cached_per_class(): void
    {
        $r = $this->renderer();
        $method = new \ReflectionMethod($r, 'buildComponentInstance');
        $method->setAccessible(true);

        $method->invoke($r, ReflFixtureA::class, ['title' => 'first']);
        $method->invoke($r, ReflFixtureA::class, ['title' => 'second']);

        $cacheProp = new \ReflectionProperty(ComponentRenderer::class, 'ctorMetaCache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue();

        $this->assertArrayHasKey(ReflFixtureA::class, $cache);
        $this->assertIsArray($cache[ReflFixtureA::class]);
        $this->assertSame('title', $cache[ReflFixtureA::class][0]['name']);
    }

    public function test_defaults_apply_when_data_missing(): void
    {
        $r = $this->renderer();
        $method = new \ReflectionMethod($r, 'buildComponentInstance');
        $method->setAccessible(true);

        $instance = $method->invoke($r, ReflFixtureA::class, []);
        $this->assertSame('Untitled', $instance->title);
        $this->assertSame(1, $instance->priority);
    }

    public function test_explicit_data_overrides_defaults(): void
    {
        $r = $this->renderer();
        $method = new \ReflectionMethod($r, 'buildComponentInstance');
        $method->setAccessible(true);

        $instance = $method->invoke($r, ReflFixtureA::class, ['title' => 'Hi', 'priority' => 7]);
        $this->assertSame('Hi', $instance->title);
        $this->assertSame(7, $instance->priority);
    }

    public function test_nullable_param_gets_null_when_absent(): void
    {
        $r = $this->renderer();
        $method = new \ReflectionMethod($r, 'buildComponentInstance');
        $method->setAccessible(true);

        $instance = $method->invoke($r, ReflFixtureB::class, []);
        $this->assertNull($instance->maybe);
    }

    public function test_no_constructor_class_works(): void
    {
        $r = $this->renderer();
        $method = new \ReflectionMethod($r, 'buildComponentInstance');
        $method->setAccessible(true);

        $instance = $method->invoke($r, ReflFixtureNoCtor::class, ['anything' => 'ignored']);
        $this->assertInstanceOf(ReflFixtureNoCtor::class, $instance);
    }
}

class ReflFixtureA extends Component
{
    public function __construct(
        public string $title = 'Untitled',
        public int $priority = 1,
    ) { parent::__construct(); }
    public function render(): string { return 'components.fake'; }
}

class ReflFixtureB extends Component
{
    public function __construct(
        public ?string $maybe = null,
    ) { parent::__construct(); }
    public function render(): string { return 'components.fake'; }
}

class ReflFixtureNoCtor extends Component
{
    public function render(): string { return 'components.fake'; }
}
