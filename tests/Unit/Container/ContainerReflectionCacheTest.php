<?php

namespace Tests\Unit\Container;

use Nitro\Container\Container;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The container caches ReflectionClass and constructor parameter metadata so
 * each class pays reflection cost exactly once per container instance.
 *
 * Without this cache, building a class N times triggers N reflections; with it,
 * the cache holds a single entry per class regardless of how many times it's
 * built.
 */
class ContainerReflectionCacheTest extends TestCase
{
    protected function setUp(): void
    {
        Container::reset();
    }

    public function test_cache_is_empty_initially(): void
    {
        $container = new Container();
        $this->assertSame([], $this->cache($container));
    }

    public function test_cache_populates_on_first_build(): void
    {
        $container = new Container();
        $container->make(ReflectionTargetSimple::class);

        $cache = $this->cache($container);
        $this->assertArrayHasKey(ReflectionTargetSimple::class, $cache);
        $this->assertNull($cache[ReflectionTargetSimple::class]['ctor']);
        $this->assertSame([], $cache[ReflectionTargetSimple::class]['params']);
    }

    public function test_cache_records_constructor_params(): void
    {
        $container = new Container();
        $container->make(ReflectionTargetWithDeps::class);

        $cache = $this->cache($container);
        $entry = $cache[ReflectionTargetWithDeps::class];
        $this->assertNotNull($entry['ctor']);
        $this->assertCount(1, $entry['params']);
        $this->assertSame('dep', $entry['params'][0]->getName());
    }

    public function test_repeat_builds_do_not_re_reflect(): void
    {
        $container = new Container();
        $container->make(ReflectionTargetSimple::class);

        $reflectorBefore = $this->cache($container)[ReflectionTargetSimple::class]['class'];

        for ($i = 0; $i < 5; $i++) {
            $container->make(ReflectionTargetSimple::class);
        }

        $reflectorAfter = $this->cache($container)[ReflectionTargetSimple::class]['class'];
        $this->assertSame($reflectorBefore, $reflectorAfter, 'ReflectionClass instance must be reused, not rebuilt.');
        $this->assertCount(1, $this->cache($container), 'Cache must not grow on repeated builds.');
    }

    public function test_clear_reflection_cache_empties_it(): void
    {
        $container = new Container();
        $container->make(ReflectionTargetSimple::class);
        $this->assertNotSame([], $this->cache($container));

        $container->clearReflectionCache();

        $this->assertSame([], $this->cache($container));
    }

    public function test_non_instantiable_class_throws(): void
    {
        $container = new Container();
        $this->expectException(\RuntimeException::class);
        $container->make(ReflectionTargetAbstract::class);
    }

    public function test_missing_class_throws_with_clear_message(): void
    {
        $container = new Container();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');
        $container->make('Tests\\Definitely\\Missing\\Class_xyz');
    }

    protected function cache(Container $container): array
    {
        $prop = new ReflectionProperty(Container::class, 'reflectionCache');
        return $prop->getValue($container);
    }
}

class ReflectionTargetSimple
{
}

class ReflectionTargetWithDeps
{
    public function __construct(public ReflectionTargetSimple $dep)
    {
    }
}

abstract class ReflectionTargetAbstract
{
}
