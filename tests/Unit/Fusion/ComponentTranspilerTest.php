<?php

namespace Tests\Unit\Fusion;

use Nitro\Fusion\Transpiler\ComponentTranspiler;
use PHPUnit\Framework\TestCase;

/**
 * ComponentTranspiler is Fusion's Nitro-aware wrapper over the raw PHP→JS engine.
 * It must: transpile Pure-UI methods, turn #[Server] methods into RPC stubs (so
 * server logic never reaches the client), collect public props, and flag
 * client-purity violations.
 */
class ComponentTranspilerTest extends TestCase
{
    private function transpile(string $body): \Nitro\Fusion\Transpiler\TranspileResult
    {
        $src = "<?php\nnamespace App\\Components;\n{$body}";
        return (new ComponentTranspiler())->transpile($src);
    }

    private const TODO = <<<'PHP'
class Todo
{
    public array $items = [];

    public function add(string $t): void
    {
        $this->items[] = $t;
    }

    #[Server]
    public function save(): void
    {
        TodoModel::create(['title' => 'x']);
    }
}
PHP;

    public function test_pure_ui_method_is_transpiled(): void
    {
        $r = $this->transpile(self::TODO);
        $this->assertStringContainsString('add(t)', $r->js);
        $this->assertStringContainsString('$this.items.push(t)', $r->js);
    }

    public function test_public_props_are_collected_as_state(): void
    {
        $r = $this->transpile(self::TODO);
        $this->assertSame(['items'], $r->publicProps);
    }

    public function test_server_method_becomes_an_rpc_stub(): void
    {
        $r = $this->transpile(self::TODO);

        $this->assertContains('save', $r->serverMethods);
        // the client stub defers to the runtime bridge (JS emits double-quoted strings)...
        $this->assertStringContainsString('__fusionCall("save"', $r->js);
        // ...and the server-only body is GONE (no ORM call shipped to the browser)
        $this->assertStringNotContainsString('TodoModel', $r->js);
        $this->assertStringNotContainsString('.create(', $r->js);
    }

    public function test_clean_component_is_pure(): void
    {
        $r = $this->transpile(self::TODO);
        $this->assertTrue($r->isPure(), 'add() is pure; save() is #[Server] so it is not purity-checked');
        $this->assertSame([], $r->violations);
    }

    public function test_server_call_in_a_pure_ui_method_is_flagged(): void
    {
        $r = $this->transpile(<<<'PHP'
class Bad
{
    public function oops(): void
    {
        Post::create(['x' => 1]);
    }
}
PHP);
        $this->assertFalse($r->isPure());
        $this->assertArrayHasKey('oops', $r->violations);
        $this->assertStringContainsString('Post::create', $r->violations['oops'][0]);
    }

    public function test_protected_props_and_trait_uses_are_not_shipped_to_the_client(): void
    {
        $r = $this->transpile(<<<'PHP'
use Nitro\Fusion\Concerns\Transpilable;
class Widget
{
    use Transpilable;
    public int $count = 0;
    protected string $apiKey = 'SECRET123';
    private array $cache = [];
    public function inc(): void { $this->count++; }
}
PHP);
        $this->assertSame(['count'], $r->publicProps);
        $this->assertStringNotContainsString('SECRET123', $r->js);   // protected value never ships
        $this->assertStringNotContainsString('apiKey', $r->js);
        $this->assertStringNotContainsString('cache', $r->js);
        $this->assertStringNotContainsString('Transpilable', $r->js); // trait is server-side
        $this->assertStringContainsString('count = 0', $r->js);
        $this->assertStringContainsString('inc()', $r->js);
    }

    public function test_new_of_a_class_in_pure_ui_is_flagged(): void
    {
        $r = $this->transpile(<<<'PHP'
class Bad2
{
    public function make(): void
    {
        $x = new Order();
    }
}
PHP);
        $this->assertFalse($r->isPure());
        $this->assertStringContainsString('instantiates Order', $r->violations['make'][0]);
    }
}
