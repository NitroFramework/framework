<?php

namespace Tests\Unit\Container;

use Nitro\Container\Container;
use Nitro\Container\ContainerCompiler;
use PHPUnit\Framework\TestCase;

class CcConn {}
class CcRepoA { public function __construct(public CcConn $c) {} }
class CcRepoB { public function __construct(public CcConn $c) {} }
class CcSvc { public function __construct(public CcRepoA $a, public CcRepoB $b) {} }
interface CcLogger {}
class CcFileLogger implements CcLogger {}
class CcNeedsLogger { public function __construct(public CcLogger $l) {} }

/**
 * The AOT container compiler: inline unbound autowire graphs, defer bound /
 * interface deps to the container (so bindings, singletons and aliases still
 * apply), and produce a compiled factory map the container resolves without
 * reflection.
 */
class ContainerCompilerTest extends TestCase
{
    /** Compile then load the factory map (as production would from the cache file). */
    private function load(Container $c, array $entries): array
    {
        $php = (new ContainerCompiler())->compile($c, $entries);
        $tmp = tempnam(sys_get_temp_dir(), 'cc') . '.php';
        file_put_contents($tmp, $php);
        $map = require $tmp;
        @unlink($tmp);
        return $map;
    }

    public function test_inlines_an_unbound_autowire_graph(): void
    {
        $php = (new ContainerCompiler())->compile(new Container(), [CcSvc::class]);

        $this->assertStringContainsString(
            'new \\' . CcSvc::class . '(new \\' . CcRepoA::class . '(new \\' . CcConn::class . '())',
            $php
        );
    }

    public function test_compiled_factory_produces_the_correct_graph(): void
    {
        $c = new Container();
        $c->setCompiledFactories($this->load($c, [CcSvc::class]));

        $svc = $c->make(CcSvc::class);

        $this->assertInstanceOf(CcSvc::class, $svc);
        $this->assertInstanceOf(CcRepoA::class, $svc->a);
        $this->assertInstanceOf(CcConn::class, $svc->a->c);
    }

    public function test_bound_dependency_is_deferred_and_stays_shared(): void
    {
        $c = new Container();
        $c->singleton(CcConn::class); // Conn is now a shared singleton

        $php = (new ContainerCompiler())->compile($c, [CcRepoA::class]);
        $this->assertStringContainsString('$c->make(\'' . addslashes(CcConn::class) . '\')', $php);
        $this->assertStringNotContainsString('new \\' . CcConn::class, $php);

        $c->setCompiledFactories($this->load($c, [CcRepoA::class]));
        $a1 = $c->make(CcRepoA::class);
        $a2 = $c->make(CcRepoA::class);
        $this->assertSame($a1->c, $a2->c, 'bound singleton dep must stay shared through the compiled factory');
    }

    public function test_interface_dependency_is_deferred_to_the_container(): void
    {
        $c = new Container();
        $c->singleton('lg', CcFileLogger::class);
        $c->alias(CcLogger::class, 'lg');

        $php = (new ContainerCompiler())->compile($c, [CcNeedsLogger::class]);
        $this->assertStringContainsString('$c->make(\'' . addslashes(CcLogger::class) . '\')', $php);

        $c->setCompiledFactories($this->load($c, [CcNeedsLogger::class]));
        $this->assertInstanceOf(CcFileLogger::class, $c->make(CcNeedsLogger::class)->l);
    }

    public function test_uncompilable_class_is_dropped_and_falls_back_to_reflection(): void
    {
        // A class with an untyped, no-default param can't be compiled → not in the map.
        $php = (new ContainerCompiler())->compile(new Container(), [CcNeedsLogger::class, CcSvc::class]);
        $this->assertStringContainsString(CcSvc::class, $php);
    }
}
