<?php

namespace Nitro\Tests\Unit;

use Illuminate\Container\Container;
use Nitro\Console\FactoryCompiler;
use Nitro\Foundation\Application;
use Nitro\Tests\TestCase;

class ContainerTest extends TestCase
{
    public function test_components_are_shared_lazy_and_overridable(): void
    {
        $app = new Application(self::APP);
        $app->instance('config', new \Illuminate\Config\Repository([]));

        $this->assertTrue($app->bound('events'));
        $this->assertFalse($app->resolved('events'));
        $this->assertSame($app->make('events'), $app->make(\Illuminate\Contracts\Events\Dispatcher::class));

        $app->singleton('files', fn () => new \ArrayObject(['custom' => true]));
        $this->assertTrue($app->make('files')['custom']);
        $this->assertTrue($app->hasExplicitBinding('files'));
    }

    public function test_extend_applies_to_components(): void
    {
        $app = new Application(self::APP);

        $app->extend('files', fn ($files) => new \ArrayObject(['wrapped' => $files]));

        $this->assertInstanceOf(\Illuminate\Filesystem\Filesystem::class, $app->make('files')['wrapped']);
        $this->assertSame($app->make('files'), $app->make('files'), 'still shared after extending');
    }

    public function test_deferred_framework_services_load_their_upstream_provider(): void
    {
        $app = $this->boot();

        $this->assertFalse($app->providerIsLoaded(\Illuminate\Bus\BusServiceProvider::class));
        $this->assertInstanceOf(\Illuminate\Bus\Dispatcher::class, $app->make(\Illuminate\Contracts\Bus\Dispatcher::class));
        $this->assertTrue($app->providerIsLoaded(\Illuminate\Bus\BusServiceProvider::class));
    }

    public function test_compiled_factories_match_reflection(): void
    {
        $app = new Application(self::APP);
        $compiler = new FactoryCompiler($app);

        [$factories, $map] = require $this->writeTemp($compiler->compile([Fx\Needs::class, Fx\Attributed::class]));

        $this->assertEqualsCanonicalizing([Fx\Needs::class, Fx\Leaf::class], array_keys($map));
        $this->assertSame([Fx\Attributed::class], $compiler->skipped, 'class attributes keep reflection');

        $app->setCompiledFactories($factories, $map);
        $compiled = $app->make(Fx\Needs::class);
        $reflected = (new Container)->make(Fx\Needs::class);

        $this->assertEquals($reflected, $compiled);
        $this->assertSame(7, $compiled->number);
        $this->assertSame('default', $compiled->name);
    }

    public function test_compiled_factories_yield_to_parameters_and_contextual_bindings(): void
    {
        $app = new Application(self::APP);
        [$factories, $map] = require $this->writeTemp((new FactoryCompiler($app))->compile([Fx\Needs::class]));
        $app->setCompiledFactories($factories, $map);

        $this->assertSame('given', $app->make(Fx\Needs::class, ['name' => 'given'])->name);

        $app->when(Fx\Needs::class)->needs('$name')->give('contextual');
        $this->assertSame('contextual', $app->make(Fx\Needs::class)->name);
    }

    private function writeTemp(string $php): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nitro').'.php';
        file_put_contents($path, $php);

        return $path;
    }
}

namespace Nitro\Tests\Unit\Fx;

class Leaf
{
}

#[\AllowDynamicProperties]
class Attributed
{
}

class Needs
{
    public function __construct(public Leaf $leaf, public int $number = 7, public string $name = 'default', public ?Attributed $optional = null)
    {
    }
}
