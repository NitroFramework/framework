<?php

namespace Nitro\Fusion\Transpiler;

use Nitro\Fusion\JsTranspile\JsTranspiler;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use RuntimeException;

/**
 * The Nitro adaptation layer over the (vendored) PHP→JS engine.
 *
 * The raw {@see JsTranspiler} is a generic PHP→JS converter with no knowledge of
 * Fusion's rules — it would happily transpile a `#[Server]` body and ship a
 * server-only `Model::create()` to the browser. This wrapper makes it a *Nitro
 * component* transpiler:
 *
 *   1. `#[Server]` methods → body replaced with an RPC stub
 *      (`$this->__fusionCall('name', [...args])`) before transpilation, so the
 *      real server logic never reaches the client.
 *   2. Pure-UI methods → scanned for client-purity: a `new SomeClass()` or a
 *      `Model::create()` reaches server land and is reported as a violation
 *      (the build fails on these), so business logic can't leak client-side.
 *   3. Public props are collected as the component's reactive state.
 *
 * The vendored engine is never modified — all Nitro-specific behaviour lives
 * here (AST pre-processing) and is re-emitted as plain PHP for the engine.
 */
class ComponentTranspiler
{
    private Parser $parser;
    private Standard $printer;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->printer = new Standard();
    }

    public function transpile(string $source): TranspileResult
    {
        $ast = $this->parser->parse($source);
        $class = $this->findClass($ast ?? []);
        if ($class === null) {
            throw new RuntimeException('Fusion: no component class found in the given source.');
        }

        $props = [];
        $serverMethods = [];
        $violations = [];
        $kept = [];

        foreach ($class->stmts as $stmt) {
            // Trait uses (e.g. `use Transpilable`) are server-side plumbing — the
            // trait's methods run on the server (SSR / #[Server]), never the client.
            if ($stmt instanceof TraitUse) {
                continue;
            }

            if ($stmt instanceof Property) {
                // Only PUBLIC props are client reactive state. Protected/private
                // props are server-only and must NOT be shipped to the browser.
                if (! $stmt->isPublic()) {
                    continue;
                }
                foreach ($stmt->props as $p) {
                    $props[] = $p->name->toString();
                }
                $kept[] = $stmt;
                continue;
            }

            if ($stmt instanceof ClassMethod) {
                $name = $stmt->name->toString();

                if ($this->isServerMethod($stmt)) {
                    $serverMethods[] = $name;
                    $stmt->stmts = $this->rpcStub($name, $stmt);
                    $stmt->attrGroups = [];              // drop #[Server]; JS has no attributes
                } elseif ($name !== '__construct') {
                    $found = $this->purityViolations($stmt);
                    if ($found !== []) {
                        $violations[$name] = $found;
                    }
                }
            }

            $kept[] = $stmt;
        }

        $class->stmts = $kept;
        $rewrittenPhp = $this->printer->prettyPrintFile($ast);
        $js = (string) (new JsTranspiler())->convert($rewrittenPhp);

        return new TranspileResult($js, $serverMethods, $props, $violations);
    }

    /** Walk top-level (optionally namespaced) statements for the first class. */
    private function findClass(array $ast): ?Class_
    {
        foreach ($ast as $node) {
            if ($node instanceof Class_) {
                return $node;
            }
            if ($node instanceof Namespace_) {
                foreach ($node->stmts as $inner) {
                    if ($inner instanceof Class_) {
                        return $inner;
                    }
                }
            }
        }
        return null;
    }

    private function isServerMethod(ClassMethod $method): bool
    {
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if (strtolower($attr->name->getLast()) === 'server') {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Replace a `#[Server]` method body with a stub that defers to the runtime
     * bridge, forwarding the method's own parameters. Built by parsing a snippet
     * so we never hand-assemble AST nodes.
     *
     * @return Node\Stmt[]
     */
    private function rpcStub(string $method, ClassMethod $original): array
    {
        $params = [];
        foreach ($original->getParams() as $param) {
            if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $params[] = '$' . $param->var->name;
            }
        }
        $args = '[' . implode(', ', $params) . ']';
        $snippet = '<?php return $this->__fusionCall(' . var_export($method, true) . ', ' . $args . ');';

        return $this->parser->parse($snippet) ?? [];
    }

    /**
     * Report why a Pure-UI method isn't client-pure: a `new X()` or a static
     * call reaches a server-side class. Pure UI should only touch `$this` state,
     * params, control flow, PHP builtins (shimmed), and other pure methods.
     *
     * @return array<int, string>
     */
    private function purityViolations(ClassMethod $method): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var array<int, string> */
            public array $found = [];

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
                    $this->found[] = 'instantiates ' . $node->class->toString()
                        . ' — move to a #[Server] method or the injected API client';
                }
                if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name) {
                    $call = $node->name instanceof Node\Identifier ? $node->name->toString() : '?';
                    $this->found[] = 'static call ' . $node->class->toString() . '::' . $call
                        . '() — server-only, move to a #[Server] method';
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($method->stmts ?? []);

        return $visitor->found;
    }
}
