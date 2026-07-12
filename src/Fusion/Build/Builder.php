<?php

namespace Nitro\Fusion\Build;

use Nitro\Fusion\Compiler\BladeCompiler;
use Nitro\Fusion\Transpiler\ComponentTranspiler;

/**
 * Turns Fusion components into the client bundle the {@see \Nitro\Fusion\js}
 * runtime consumes. Each component contributes:
 *   - its transpiled JS class (Pure-UI methods; #[Server] methods → RPC stubs),
 *   - its compiled render function (from the Blade view),
 *   - metadata (server methods, fusion:model props, events, public props).
 *
 * A component that isn't client-pure fails the build ({@see FusionBuildException}).
 */
class Builder
{
    public function __construct(
        private ComponentTranspiler $transpiler = new ComponentTranspiler(),
        private BladeCompiler $blade = new BladeCompiler(),
    ) {
    }

    /**
     * Compile one component (class + view) into its bundle artifact.
     *
     * @return array{name: string, classJs: string, renderJs: string, meta: array}
     */
    public function compileComponent(string $name, string $php, string $template): array
    {
        $t = $this->transpiler->transpile($php);

        if (! $t->isPure()) {
            throw new FusionBuildException($name, $t->violations);
        }

        $c = $this->blade->compile($template, $t->publicProps);

        return [
            'name'     => $name,
            'classJs'  => $t->js,
            'renderJs' => $c->js,
            'meta'     => [
                'props'   => $t->publicProps,
                'server'  => $t->serverMethods,
                'models'  => $c->models,
                'events'  => $c->events,
            ],
        ];
    }

    /**
     * Assemble compiled components into a single browser bundle that self-registers
     * each into `window.__fusion.registry`. The runtime then mounts by name.
     *
     * @param array<int, array{name: string, classJs: string, renderJs: string, meta: array}> $artifacts
     */
    public function bundle(array $artifacts): string
    {
        $out = "window.__fusion = window.__fusion || { registry: {} };\n";

        foreach ($artifacts as $a) {
            $name = json_encode($a['name']);
            $meta = json_encode($a['meta'], JSON_UNESCAPED_SLASHES);

            // Each component in its own scope; the transpiled class name matches
            // the component's short name, so we can reference it directly.
            $out .= "(function () {\n"
                . $a['classJs'] . "\n"
                . "const __render = " . $a['renderJs'] . ";\n"
                . "window.__fusion.registry[{$name}] = { component: {$a['name']}, render: __render, meta: {$meta} };\n"
                . "})();\n";
        }

        return $out;
    }
}
