<?php

namespace Nitro\Fusion\Runtime;

use Nitro\Container\Contracts\ContainerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Server-side render of a #[Client] component for first paint. It resolves the
 * component through the container (so DI + mount() run), renders its reactive
 * Blade view to HTML, serializes the public props as the hydration state, and
 * wraps it in the `[data-fusion-root]` element the runtime mounts.
 *
 * v1 renders the reactive SUBSET directly so the SSR HTML is byte-identical to
 * what the client's compiled render produces for the same state — no hydration
 * flash. `{{ $expr }}` beyond a bare prop and `{{ $this->method() }}` in the
 * view (which the client already transpiles) are a documented v2 for SSR.
 */
class FusionRenderer
{
    public function __construct(
        private ContainerInterface $container,
        private string $namespace = 'App\\Fusion\\Components\\',
    ) {
    }

    /** @param array<string,mixed> $mount Initial props / mount() arguments. */
    public function render(string $componentClass, array $mount = []): string
    {
        // Accept a short name (@fusion('Counter')) or a FQCN (@fusion(Counter::class)).
        if (! str_contains($componentClass, '\\')) {
            $componentClass = $this->namespace . $componentClass;
        }

        $component = $this->container->make($componentClass);

        foreach ($mount as $key => $value) {
            if (property_exists($component, $key)) {
                $component->{$key} = $value;
            }
        }
        if (method_exists($component, 'mount')) {
            $this->container->call([$component, 'mount'], $mount);
        }

        $html = $this->renderSubset($this->viewSourceFor($componentClass), $component);
        $name = $this->shortName($componentClass);
        $state = $this->state($component);

        return '<div data-fusion-root data-fusion-name="' . htmlspecialchars($name, ENT_QUOTES) . '"'
            . " data-fusion-state='" . $state . "'>" . $html . '</div>';
    }

    /** Locate the component's co-located Blade view (Counter.php → Counter.blade.php). */
    private function viewSourceFor(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $view = substr($file, 0, -4) . '.blade.php';   // strip ".php", add ".blade.php"

        if (! is_file($view)) {
            throw new RuntimeException("Fusion: no view for [{$class}] at {$view}");
        }
        return (string) file_get_contents($view);
    }

    /** Render the reactive subset to match the client's compiled render exactly. */
    private function renderSubset(string $template, object $component): string
    {
        // fusion:<event> / fusion:model → data-fusion-* (same attrs the runtime binds)
        $html = preg_replace(
            '/\bfusion:(click|submit|change|input|keydown|keyup|blur|focus)(?:\.[\w.]+)?="(\w+)"/',
            'data-fusion-$1="$2"',
            $template
        );
        $html = preg_replace('/\bfusion:model(?:\.[\w.]+)?="\$?(\w+)"/', 'data-fusion-model="$1"', $html);

        // {!! $prop !!} raw, then {{ $prop }} escaped
        $html = preg_replace_callback(
            '/\{!!\s*\$(\w+)\s*!!\}/',
            fn (array $m): string => (string) ($component->{$m[1]} ?? ''),
            $html
        );
        return preg_replace_callback(
            '/\{\{\s*\$(\w+)\s*\}\}/',
            fn (array $m): string => htmlspecialchars((string) ($component->{$m[1]} ?? ''), ENT_QUOTES),
            $html
        );
    }

    private function state(object $component): string
    {
        $state = method_exists($component, 'fusionState')
            ? $component->fusionState()
            : get_object_vars($component);

        return (string) json_encode($state, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    }

    private function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }

    /**
     * The `<script>` tags for the built bundle + runtime, plus the CSRF token the
     * runtime attaches to #[Server] calls. Emitted by the @fusionScripts directive
     * (place once before </body>). Bundle first (registers components), then the
     * runtime (mounts them).
     */
    public static function scripts(): string
    {
        $csrf    = function_exists('csrf_token') ? csrf_token() : '';
        $callUri = function_exists('config') ? (string) config('fusion.call_uri', '/nitro/fusion/call') : '/nitro/fusion/call';
        $flags   = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT;

        return '<script src="/nitro/fusion-app.js"></script>'
            . '<script>window.__fusion=window.__fusion||{registry:{}};'
            . 'window.__fusion.csrf=' . json_encode($csrf, $flags) . ';'
            . 'window.__fusion.callUri=' . json_encode($callUri, $flags) . ';</script>'
            . '<script src="/nitro/fusion.js"></script>';
    }
}
