<?php

namespace Nitro\View\Engine;

use Nitro\Container\Container;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\PathRegistry;
use Nitro\Support\Arr;

// These 4 use statements change:
use Nitro\View\Contracts\TemplateCache;
use Nitro\View\Contracts\TemplateCompiler;
use Nitro\View\Contracts\TagCompiler;
use Nitro\View\Contracts\ComponentEngine;

use Nitro\View\Support\Htmlable;
use Nitro\View\Contracts\ViewEngine;
use Nitro\View\Support\DebugRenderPipeline;
use Nitro\View\Support\ViewManifest;

use RuntimeException;

/**
 * Blade template view renderer.
 *
 * Resolves view names to template files, delegates compilation and caching
 * to CompiledTemplateCache, and executes compiled templates with
 * section/layout inheritance.
 *
 * Compiled templates run in this instance's scope so $this->render(),
 * $this->getSection(), etc. are available inside Blade files.
 *
 * RESPONSIBILITIES:
 * - Resolve view name (dot notation) to filesystem path; cache paths in memory
 * - Ask CompiledTemplateCache for the compiled file path
 * - Execute compiled file via include with extract($data)
 * - Handle @extends by re-rendering the parent with stored sections
 * - Delegate section/stack/slot state to SectionManager
 * - Expose template-context methods for compiled Blade directives
 *
 * NOT RESPONSIBLE FOR:
 * - Compiling Blade source to PHP (BladeCompiler, owned by the cache)
 * - Writing, locking, or invalidating cache files (CompiledTemplateCache)
 * - Opcache integration (CompiledTemplateCache)
 *
 * @package Nitro\View
 */
class ViewRenderer implements ViewEngine
{
    use Concerns\ManagesLayouts,
        Concerns\ManagesStacks,
        Concerns\ManagesFragments,
        Concerns\ManagesLoops,
        Concerns\ManagesStream;

    protected string $viewsPath;
    protected string $extension;
    protected bool $debug;

    /** In-memory cache of resolved view paths (process-lifetime, not per-render). */
    protected array $resolvedPaths = [];

    /** Registered view namespaces: [namespace => absolute base directory]. */
    protected array $viewHints = [];

    protected bool $debugRender = false;

    /** Cache of which views use @stream (process-lifetime, not per-render). */
    protected array $streamViewCache = [];

    /**
     * All per-render transient state — sections, stacks, fragments, teleports,
     * loops, stream flags, render depth, @once ids. Replaced with a fresh
     * instance per top-level render, so render state never leaks between
     * renders or (in worker mode) between requests. This is what makes the
     * renderer safe to share as a singleton.
     */
    protected RenderContext $context;




    public function __construct(
        protected readonly TemplateCache $templateCache,
        protected readonly ComponentEngine $components,
        protected readonly TemplateCompiler $compiler,
        protected readonly TagCompiler $tagCompiler,
        PathRegistry $paths,
        ConfigRepository $config
    ) {
        // Now the class pulls what it needs from the objects
        $this->viewsPath = $paths->views();
        $this->extension = $config->get('view.extension');
        $this->debug     = (bool) $config->get('app.debug');
        $this->context   = new RenderContext();
        if ($config->get('view.debug_render')) {
            $this->debugRender = true;
            DebugRenderPipeline::enable();
        }
    }

    // -----------------------------------------------------------------------
    // Public rendering API
    // -----------------------------------------------------------------------

    /**
     * Render a view to HTML with optional layout inheritance.
     *
     * @param string               $view View name in dot notation (e.g. 'pages.home')
     * @param array<string, mixed> $data Variables to pass to the template
     * @return string Rendered HTML
     * @throws RuntimeException If template is not found or execution throws
     */
    public function render(string $view, array $data = []): string
    {
        $isTopLevel = ($this->context->renderCount === 0);

        // Debug instrumentation is gated at the call site so the array
        // literals + method-call frames aren't even built when debug is off.
        // For non-debug requests this collapses to a single isEnabled()
        // bool read.
        if (DebugRenderPipeline::isEnabled()) {
            DebugRenderPipeline::enter('render', [
                'view'         => $view,
                'renderCount'  => $this->context->renderCount,
                'isTopLevel'   => $isTopLevel ? 'yes' : 'no',
                'sectionStack' => $this->context->sectionStack,
            ]);
        }

        if ($isTopLevel) {
            if (DebugRenderPipeline::isEnabled()) {
                DebugRenderPipeline::note('flushState()');
            }
            $this->flushState();

            if ($this->isStreamView($view)) {
                $this->renderStream($view, $data);
                if (DebugRenderPipeline::isEnabled()) {
                    DebugRenderPipeline::exit('render', ['output_length' => 0, 'mode' => 'stream']);
                }
                return '';
            }
        }

        // The inline <!-- BLADE DEBUG --> annotation is a view-render diagnostic,
        // so it honours the dedicated view.debug_render flag rather than the broad
        // app.debug — otherwise every dev request pollutes its HTML with it.
        $result = ($this->debugRender && $isTopLevel)
            ? $this->debugRender($view, $data)
            : $this->renderFromFile($view, $data);

        if (DebugRenderPipeline::isEnabled()) {
            DebugRenderPipeline::exit('render', ['output_length' => strlen($result)]);

            if ($isTopLevel && $this->debugRender) {
                DebugRenderPipeline::save();
            }
        }

        return $result;
    }

    /**
     * Detect whether a view uses @stream.
     *
     * Lookup order:
     *   1. Per-request in-memory cache (fastest — return on second hit).
     *   2. Optimize-time manifest (one autoload, no file stat per view).
     *   3. Live probe of the first 256 bytes of the source — only when
     *      the manifest hasn't been generated yet (dev mode).
     *
     * Streaming is always skipped for HTMX partial requests and fragment
     * requests regardless of what the view declares.
     */
    protected function isStreamView(string $view): bool
    {
        $container = Container::getInstance();
        if ($container->has('request')) {
            $request = $container->make('request');
            // HTMX partials and fragment requests never stream — ask the bound
            // Request rather than reading $_GET directly.
            if ($request->isHtmx() || !empty($request->query('_fragment'))) {
                return false;
            }
        }

        if (isset($this->streamViewCache[$view])) {
            return $this->streamViewCache[$view];
        }

        $templateFile = $this->getTemplatePath($view);

        // Trust the manifest only when it's at least as new as the source view.
        // A view edited after `optimize` (e.g. @stream added/removed) would
        // otherwise be misrouted; when stale, fall through to the live probe.
        $manifestVerdict = ViewManifest::isStream($view);
        if ($manifestVerdict !== null && ViewManifest::isFresh($templateFile)) {
            return $this->streamViewCache[$view] = $manifestVerdict;
        }

        $firstBytes   = @file_get_contents($templateFile, false, null, 0, 256);
        if ($firstBytes === false) {
            return $this->streamViewCache[$view] = false;
        }
        return $this->streamViewCache[$view] = str_contains($firstBytes, '@stream');
    }

    /**
     * Execute a stream template.
     *
     * Cleans up dangling output buffers from @fill if an exception
     * is thrown mid-stream, preventing OB stack corruption.
     */
    protected function renderStream(string $view, array $data): void
    {
        $templateFile = $this->getTemplatePath($view);
        $compiledFile = $this->templateCache->resolve($templateFile, $view);

        extract($data, EXTR_SKIP);

        try {
            include $compiledFile;
        } catch (\Throwable $e) {
            // Clean up any dangling fill buffer
            if ($this->context->currentFill !== null) {
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                $this->context->currentFill = null;
            }
            $this->endStream();

            throw new \RuntimeException(
                "Stream template error: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Render a view without section/layout inheritance.
     *
     * Use for partials or embedded components where @extends would be wrong.
     *
     * @param string               $view View name in dot notation
     * @param array<string, mixed> $data Variables for the template
     * @return string Rendered HTML
     */
    public function renderPartial(string $view, array $data = []): string
    {
        $this->context->renderCount++;

        try {
            $templateFile = $this->getTemplatePath($view);
            $compiledFile = $this->templateCache->resolve($templateFile, $view);
            return $this->executeTemplate($compiledFile, $data);
        } finally {
            // Balance depth even on a template exception — see renderFromFile().
            $this->context->renderCount--;
        }
    }

    /**
     * Pre-compile a view without rendering it (cache warming).
     *
     * @param string $view View name in dot notation
     */
    public function compileOnly(string $view): void
    {
        $templateFile = $this->getTemplatePath($view);
        $this->templateCache->compile($templateFile, $view);
    }

    // -----------------------------------------------------------------------
    // Cache delegation
    // -----------------------------------------------------------------------

    /** Delete all compiled template files. */
    public function clearCache(): void
    {
        $this->templateCache->clear();
    }

    /** Delete the compiled file for a single view. */
    public function clearViewCache(string $view): void
    {
        $this->templateCache->clearView($view);
    }

    /**
     * Return cache statistics.
     *
     * @return array<string, mixed>
     */
    public function getCacheStats(): array
    {
        return $this->templateCache->getStats();
    }

   
   
    // -----------------------------------------------------------------------
    // Internal rendering
    // -----------------------------------------------------------------------

    /**
     * Resolve view path, get compiled file, execute, and optionally render parent layout.
     */
    protected function renderFromFile(string $view, array $data = []): string
    {
        $this->context->renderCount++;
        $previousParentView = $this->context->parentView;
        $this->context->parentView = null;

        try {
            $debug = DebugRenderPipeline::isEnabled();
            if ($debug) {
                DebugRenderPipeline::enter('renderFromFile', [
                    'view' => $view,
                    'renderCount' => $this->context->renderCount,
                ]);
            }

            $templateFile = $this->getTemplatePath($view);
            $compiledFile = $this->templateCache->resolve($templateFile, $view);
            $output       = $this->executeTemplate($compiledFile, $data);

            if ($parentView = $this->getParentView()) {
                if ($debug) {
                    DebugRenderPipeline::note("parentView detected: {$parentView}");
                }
                $this->clearParentView();
                $output = $this->renderFromFile($parentView, $data);
            }

            if ($debug) {
                DebugRenderPipeline::exit('renderFromFile', [
                    'view' => $view,
                    'output_length' => strlen($output),
                ]);
            }

            return $output;
        } finally {
            // Always balance render depth + parentView, even if the template
            // throws. The renderer is a long-lived singleton, so a leaked
            // renderCount would make the NEXT request's top-level render look
            // nested and skip flushState() — leaking sections/stacks across
            // requests. This keeps the "safe by construction" guarantee under
            // exceptions.
            $this->context->parentView = $previousParentView;
            $this->context->renderCount--;
        }
    }

    /**
     * Run a compiled PHP file with data as variables; return captured output.
     *
     * Uses extract(EXTR_SKIP) and include so $this inside the template resolves
     * to this ViewRenderer instance — making all directive methods available.
     *
     * @param string               $compiledFile Absolute path to the compiled .php file
     * @param array<string, mixed> $data         Variables to extract into template scope
     * @return string Output captured from the template
     * @throws RuntimeException If the template throws during execution
     */
    protected function executeTemplate(string $compiledFile, array $data): string
    {
        extract($data, EXTR_SKIP);

        ob_start();

        try {
            include $compiledFile;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw new RuntimeException(
                "Error executing template: " . $e->getMessage() .
                    "\nFile: " . $compiledFile,
                0,
                $e
            );
        }

        return (string) ob_get_clean();
    }

    /**
     * Register a view namespace so `namespace::view` resolves under $path.
     *
     * Used by module providers (via ServiceProvider::loadViewsFrom) to expose a
     * module's views, e.g. addNamespace('blog', '/app/Modules/Blog/views') makes
     * view('blog::dashboard') resolve to that directory. The compiled-cache key
     * is an md5 of the full view name, so namespaced views never collide with
     * root views of the same name.
     *
     * @param string $namespace Namespace hint, without the '::' (e.g. 'blog').
     * @param string $path      Absolute directory the namespace's views live in.
     */
    public function addNamespace(string $namespace, string $path): void
    {
        $this->viewHints[$namespace] = rtrim($path, '/\\');
    }

    /**
     * Resolve a view name to its absolute filesystem path.
     *
     * Converts dot notation to directory separators, appends the extension, and
     * caches the result in memory for the duration of the request. A view name
     * of the form `namespace::view` resolves under that namespace's registered
     * directory instead of the application views path.
     *
     * @throws RuntimeException If no file exists at the resolved path, or the
     *                          view's namespace has not been registered.
     */
    protected function getTemplatePath(string $view): string
    {
        if (isset($this->resolvedPaths[$view])) {
            return $this->resolvedPaths[$view];
        }

        $basePath = $this->viewsPath;
        $name     = $view;

        $separator = strpos($view, '::');
        if ($separator !== false) {
            $namespace = substr($view, 0, $separator);
            $name      = substr($view, $separator + 2);

            if (!isset($this->viewHints[$namespace])) {
                throw new RuntimeException(
                    "View namespace '{$namespace}::' is not registered (view '{$view}')."
                );
            }

            $basePath = $this->viewHints[$namespace];
        }

        $relative     = str_replace('.', DIRECTORY_SEPARATOR, $name);
        $templateFile = rtrim($basePath, '/\\')
            . DIRECTORY_SEPARATOR
            . $relative
            . '.'
            . $this->extension;

        if (!file_exists($templateFile)) {
            throw new RuntimeException(
                "Template not found: {$view}\n" .
                    "Searched paths:\n- {$templateFile}"
            );
        }

        return $this->resolvedPaths[$view] = $templateFile;
    }

    // -----------------------------------------------------------------------
    // Debug
    // -----------------------------------------------------------------------

    /**
     * Render and append an HTML comment with timing and cache metadata.
     */
    protected function debugRender(string $view, array $data = []): string
    {
        $startTime    = microtime(true);
        $output       = $this->renderFromFile($view, $data);
        $elapsed      = round((microtime(true) - $startTime) * 1000, 2);
        $templateFile = $this->getTemplatePath($view);
        $compiledFile = $this->templateCache->getCacheFilePath($view);

        $debugInfo = [
            'view'           => $view,
            'template'       => $templateFile,
            'compiled'       => $compiledFile,
            'render_time_ms' => $elapsed,
            'output_length'  => strlen($output),
            'cache_hit'      => file_exists($compiledFile),
            'sections'       => array_keys($this->getAllSections()),
            'parent_view'    => $this->getParentView() ?? 'none',
        ];

        return $output . "\n<!-- BLADE DEBUG: " . json_encode($debugInfo, JSON_PRETTY_PRINT) . " -->";
    }





    // -----------------------------------------------------------------------
    // Component rendering — delegates to ComponentRenderer
    // -----------------------------------------------------------------------

    public function renderComponent(string $name, array $attributes = [], string $slot = ''): void
    {
        $this->components->renderSelfClosing($name, $attributes, $slot);
    }

    public function startComponent(string $name, array $attributes = []): void
    {
        $this->components->start($name, $attributes);
    }

    public function endComponent(): string
    {
        return $this->components->end();
    }

    public function startNamedSlot(string $name): void
    {
        $this->components->startNamedSlot($name);
    }

    public function endNamedSlot(): void
    {
        $this->components->endNamedSlot();
    }

    public function getAwareData(array $keys): array
    {
        return $this->components->getAwareData($keys);
    }

    public function resolveComponentProps(array $propDefaults, array $componentData): array
    {
        return $this->components->resolveComponentProps($propDefaults, $componentData);
    }













    public function renderFragment(string $view, string $fragment, array $data = []): string
    {
        $this->flushFragments();
        $this->renderPartial($view, $data);

        $result = $this->getFragment($fragment);

        if ($result === null) {
            throw new \RuntimeException("Fragment '{$fragment}' not found in view '{$view}'.");
        }

        return $result;
    }

    public function renderFragments(string $view, array $fragments, array $data = []): string
    {
        $this->flushFragments();
        $this->renderPartial($view, $data);

        $parts = [];
        foreach ($fragments as $i => $name) {
            $html = $this->getFragment($name);
            if ($html === null) {
                throw new \RuntimeException("Fragment '{$name}' not found in view '{$view}'.");
            }

            if ($i > 0) {
                $html = preg_replace(
                    '/^(\s*<\w+)/',
                    '$1 hx-swap-oob="true"',
                    trim($html),
                    1
                );
            }
            $parts[] = $html;
        }

        return implode("\n", $parts);
    }




    public function renderView(View $view): string
    {
        return $this->render($view->template(), $view->getData());
    }

    /**
     * Legacy entry point for compiled templates that still reference
     * $this->e(). New compilations emit \nitro_e() directly. Kept here so
     * cached compiled templates from a previous compiler version don't
     * break before they're regenerated, and so external code that calls
     * $renderer->e(...) continues to work.
     */
    public function e(mixed $value): string
    {
        return \nitro_e($value);
    }


    /**
     * Compile and render a raw Blade string with the given data.
     * Used primarily for testing without filesystem involvement.
     * A temp file is used so $this context works inside compiled templates.
     *
     * @param string               $blade Raw Blade template string
     * @param array<string, mixed> $data  Variables to pass to the template
     * @return string Rendered HTML
     */
    public function renderString(string $blade, array $data = []): string
    {
        // Step 1: Compile component tags first
        $compiled = $this->tagCompiler->compile($blade);      // ← was: new ComponentTagCompiler()

        // Step 2: Compile Blade directives
        $compiled = $this->compiler->compile($compiled);       // ← was: new BladeCompiler()

        // Step 3: Write to temp file so $this resolves correctly via include
        $tempFile = tempnam(sys_get_temp_dir(), 'nitro_blade_') . '.php';
        file_put_contents($tempFile, $compiled);

        try {
            return $this->executeTemplate($tempFile, $data);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function hasRenderedOnce(string $id): bool
    {
        return isset($this->context->renderedOnce[$id]);
    }

    public function markRenderedOnce(string $id): void
    {
        $this->context->renderedOnce[$id] = true;
    }

    public function startTeleport(string $target): void
    {
        $this->context->currentTeleport = $target;
        ob_start();
    }

    public function endTeleport(): void
    {
        $content = ob_get_clean();
        $target  = $this->context->currentTeleport;

        if (!isset($this->context->teleportBuffers[$target])) {
            $this->context->teleportBuffers[$target] = '';
        }

        $this->context->teleportBuffers[$target] .= $content;
        $this->context->currentTeleport = null;
    }

    public function yieldTeleport(string $target): string
    {
        return $this->context->teleportBuffers[$target] ?? '';
    }

    public function clearTeleports(): void
    {
        $this->context->teleportBuffers = [];
        $this->context->currentTeleport = null;
    }

    // -----------------------------------------------------------------------
// Include runtime methods (called by compiled @include directives)
// -----------------------------------------------------------------------

    /**
     * Render an included view, merging parent scope variables.
     *
     * @param string $view  View name
     * @param array  $data  Optional extra data
     * @param array  $vars  Parent scope vars from get_defined_vars()
     */
    public function renderInclude(string $view, array $data = [], array $vars = []): string
    {
        // If called with 2 args: renderInclude('view', get_defined_vars())
        // If called with 3 args: renderInclude('view', ['key' => 'val'], get_defined_vars())
        if (func_num_args() === 2 && !empty($data)) {
            // $data is actually get_defined_vars() — no extra merge data
            return $this->renderPartial($view, $data);
        }

        return $this->renderPartial($view, array_merge($vars, $data));
    }

    /**
     * Conditionally render a view when the condition is true.
     * Signature: renderIncludeWhen($condition, $view, $data, $vars)
     */
    public function renderIncludeWhen(bool $condition, string $view, array $data = [], array $vars = []): string
    {
        if (!$condition) {
            return '';
        }

        return $this->renderInclude($view, $data, $vars);
    }

    /**
     * Conditionally render a view when the condition is false.
     */
    public function renderIncludeUnless(bool $condition, string $view, array $data = [], array $vars = []): string
    {
        return $this->renderIncludeWhen(!$condition, $view, $data, $vars);
    }

    /**
     * Render the first view that exists from an array of views.
     */
    public function renderIncludeFirst(array $views, array $data = [], array $vars = []): string
    {
        foreach ($views as $view) {
            if ($this->viewExists($view)) {
                if (empty($vars)) {
                    return $this->renderPartial($view, $data);
                }
                return $this->renderPartial($view, array_merge($vars, $data));
            }
        }

        throw new \RuntimeException(
            'None of the views [' . implode(', ', $views) . '] exist.'
        );
    }

    /**
     * Render a view for each item in a collection.
     *
     * Empty-detection is deferred to the first foreach step so non-countable
     * iterators (generators, lazy collections) aren't exhausted before the
     * loop starts — the previous version called iterator_count() which walks
     * to the end, leaving foreach with nothing to iterate.
     *
     * @param string $view     View to render per item
     * @param iterable $data   Items to iterate
     * @param string $itemVar  Variable name for each item in the view
     * @param string $empty    View to render if collection is empty
     */
    public function renderEach(string $view, iterable $data, string $itemVar, string $empty = ''): string
    {
        $result = '';
        $rendered = 0;

        foreach ($data as $key => $value) {
            $result .= $this->renderPartial($view, [
                $itemVar => $value,
                'key'    => $key,
            ]);
            $rendered++;
        }

        if ($rendered === 0) {
            return $empty !== '' ? $this->renderPartial($empty) : '';
        }

        return $result;
    }

    /**
     * Check if a view template file exists.
     */
    public function viewExists(string $view): bool
    {
        try {
            $this->getTemplatePath($view);
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Convert an array of class conditions to a CSS class string.
     *
     * Numeric keys are always included. String keys are included when value is truthy.
     *   ['font-bold', 'text-red' => $isError, 'hidden' => false]
     *   → "font-bold text-red"
     */
    public function toCssClasses(array $classes): string
    {
        return Arr::toCssClasses($classes);
    }

    /**
     * Convert an array of style conditions to a CSS style string.
     *
     * Numeric keys are always included. String keys are included when value is truthy.
     *   ['color: red', 'font-weight: bold' => $isBold, 'display: none' => false]
     *   → "color: red; font-weight: bold;"
     */
    public function toCssStyles(array $styles): string
    {
        return Arr::toCssStyles($styles);
    }

    /**
     * Reset all per-render state by swapping in a fresh context. Called at the
     * start of every top-level render (and by the worker reset). Because every
     * top-level render starts from a clean context, render state cannot leak
     * between renders or between worker requests — the renderer is safe to
     * share as a singleton without manual per-field clearing.
     */
    public function flushState(): void
    {
        $this->context = new RenderContext();
    }

    public function enableRenderDebug(): void
    {
        $this->debugRender = true;
        DebugRenderPipeline::enable();
    }

    public function disableRenderDebug(): void
    {
        $this->debugRender = false;
        DebugRenderPipeline::disable();
    }

    /**
     * Render a compiled template with $this bound to a custom context object.
     * Used by reactive component layers so $this in component templates refers to the Component instance.
     *
     * Directive methods ($this->startSection, etc.) are forwarded via __call
     * on the ComponentContext wrapper.
     */
    public function renderWithContext(string $view, array $data, object $context): string
    {
        $this->context->renderCount++;

        try {
            $templateFile = $this->getTemplatePath($view);
            $compiledFile = $this->templateCache->resolve($templateFile, $view);

            // Create a closure that does the include, then bind it to the context object
            $executor = function (string $__compiledFile, array $__data) {
                extract($__data, EXTR_SKIP);
                ob_start();
                try {
                    include $__compiledFile;
                } catch (\Throwable $e) {
                    ob_end_clean();
                    throw new \RuntimeException(
                        "Error executing template: " . $e->getMessage() .
                            "\nFile: " . $__compiledFile,
                        0,
                        $e
                    );
                }
                return (string) ob_get_clean();
            };

            // Bind the closure so $this inside the include is the context object
            $bound = \Closure::bind($executor, $context, get_class($context));

            return $bound($compiledFile, $data);
        } finally {
            // Balance depth even on a template exception — see renderFromFile().
            $this->context->renderCount--;
        }
    }
}
