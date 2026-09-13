<?php

namespace Nitro\Blaze;

use Nitro\View\Contracts\TemplateCompiler;

/**
 * Decides which components Blaze optimizes and compiles each one into a cached
 * PHP function (a closure that renders the component body from its resolved
 * data). Only plain-template (anonymous) components that live in an optimized
 * directory or carry an @blaze marker are eligible; everything else is left to
 * Nitro's normal component pipeline.
 */
class BlazeManager
{
    /** @var string[] Absolute directories whose components are optimized. */
    protected array $directories = [];

    /** @var array<string, bool> Memoized isEnabled() results per component name. */
    protected array $enabledCache = [];

    /** @var array<string, callable> Required component function factories, by name. */
    protected array $factories = [];

    /** @var array<string, true> Components currently mid-compile (recursion guard). */
    protected array $compiling = [];

    public function __construct(
        protected bool $enabled,
        protected string $viewsPath,
        protected string $cachePath,
        array $directories = [],
    ) {
        $this->directories = array_map([$this, 'normalize'], $directories);
    }

    /** Register an additional directory of components to optimize. */
    public function optimizeDirectory(string $directory): void
    {
        $this->directories[] = $this->normalize($directory);
        $this->enabledCache = [];
    }

    public function isMasterEnabled(): bool
    {
        return $this->enabled;
    }

    /** Whether a component name should be compiled by Blaze. */
    public function isEnabled(string $name): bool
    {
        if (! $this->enabled || isset($this->compiling[$name])) {
            return false;
        }

        if (isset($this->enabledCache[$name])) {
            return $this->enabledCache[$name];
        }

        $path = $this->componentPath($name);

        $enabled = $path !== null
            && ! $this->isClassBased($name)
            && ($this->inOptimizedDirectory($path) || $this->hasBlazeMarker($path));

        return $this->enabledCache[$name] = $enabled;
    }

    /** The blade file backing a component name, or null if it does not exist. */
    public function componentPath(string $name): ?string
    {
        $relative = str_replace(['.', ':'], '/', $name);
        $path = $this->viewsPath . '/components/' . $relative . '.blade.php';

        return is_file($path) ? $this->normalize($path) : null;
    }

    /**
     * Return the component's render function, bound to the given runtime as
     * $this (so $this->resolveComponentProps inside @props resolves). Null when
     * the component can't be compiled — the runtime then falls back to core.
     */
    public function functionFor(string $name, BlazeRuntime $runtime): ?callable
    {
        if (! isset($this->factories[$name])) {
            $file = $this->compile($name);
            if ($file === null) {
                return null;
            }
            $this->factories[$name] = require $file;
        }

        return \Closure::bind($this->factories[$name], $runtime, BlazeRuntime::class);
    }

    /**
     * Compile a component into a cached function file, returning its path (or
     * null if the component is missing). The function extracts the resolved
     * component data and renders the compiled body.
     */
    /**
     * Lift `use X;` imports out of a compiled body so they can sit above the
     * closure the body is about to become.
     *
     * Only class imports at the start of a statement are moved, and only the
     * plain `use Foo\Bar;` / `use Foo\Bar as Baz;` / `use function …` forms —
     * a closure's own `function () use ($x)` is a different construct entirely
     * and must be left exactly where it is.
     *
     * @return array{0: string, 1: string}  [imports, body without them]
     */
    protected function hoistImports(string $body): array
    {
        $imports = [];

        // The delimiter before the keyword is captured and put back, so this
        // only ever matches a statement-position `use` — never the `use ($n)`
        // clause of a closure, and never the word in prose.
        $body = preg_replace_callback(
            '/(^|[;{}]|<\?php)(\s*)use\s+((?:function\s+|const\s+)?\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*(?:\s+as\s+[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)?)\s*;/m',
            function (array $matches) use (&$imports): string {
                $imports[] = 'use ' . preg_replace('/\s+/', ' ', trim($matches[3])) . ';';

                return $matches[1];
            },
            $body
        );

        if ($imports === []) {
            return ['', $body];
        }

        return [implode("\n", array_unique($imports)) . "\n\n", $body];
    }

    public function compile(string $name): ?string
    {
        $source = $this->componentPath($name);
        if ($source === null) {
            return null;
        }

        $cacheFile = $this->cachePath . '/' . hash('xxh128', $source) . '.php';

        if (is_file($cacheFile) && filemtime($cacheFile) >= filemtime($source)) {
            return $cacheFile;
        }

        // Compile the body through the core compiler (handles @props, echoes,
        // and nested components). Guard against a component referencing itself.
        $this->compiling[$name] = true;
        try {
            $body = $this->compiler()->compile(file_get_contents($source));
        } finally {
            unset($this->compiling[$name]);
        }

        // A component's body becomes a closure, and PHP allows an import only
        // at the top level of a file — so `@php use App\Support\Money; @endphp`,
        // which is ordinary Blade and compiles fine through the core engine,
        // was a parse error here. Hoist the imports above the closure.
        [$imports, $body] = $this->hoistImports($body);

        $php = "<?php\n\n" . $imports
            . "return function (array \$__data) {\n"
            . "    extract(\$__data, EXTR_SKIP);\n"
            . "    ob_start();\n"
            . "    try {\n"
            . "?>" . $body . "<?php\n"
            . "    } catch (\\Throwable \$e) { ob_end_clean(); throw \$e; }\n"
            . "    return ob_get_clean();\n"
            . "};\n";

        if (! is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0775, true);
        }
        file_put_contents($cacheFile, $php);
        unset($this->factories[$name]);

        return $cacheFile;
    }

    /** A class-backed component (App\View\Components\…) — Blaze skips these. */
    protected function isClassBased(string $name): bool
    {
        $class = 'App\\View\\Components\\' . implode('\\', array_map(
            static fn(string $part): string => str_replace('-', '', ucwords($part, '-')),
            explode('.', $name)
        ));

        return class_exists($class);
    }

    protected function inOptimizedDirectory(string $path): bool
    {
        foreach ($this->directories as $dir) {
            if (str_starts_with($path, $dir)) {
                return true;
            }
        }

        return false;
    }

    protected function hasBlazeMarker(string $path): bool
    {
        // @blaze is a top-of-file opt-in directive, so probe the first bytes
        // instead of reading the whole component — the same bounded-read pattern
        // ViewRenderer uses to sniff @stream.
        $head = @file_get_contents($path, false, null, 0, 512);

        return $head !== false && str_contains($head, '@blaze');
    }

    protected function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    protected function compiler(): TemplateCompiler
    {
        return app(TemplateCompiler::class);
    }
}
