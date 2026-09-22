<?php

namespace Nitro\View\Engines;

use Nitro\Events\Concerns\DispatchesEvents;
use Nitro\Events\Contracts\ReceivesDispatcher;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\Contracts\ResetsBetweenRequests;
use Nitro\Foundation\Contracts\PathRegistry;
use Nitro\Support\Arr;
use Nitro\View\Concerns\ManagesFragments;
use Nitro\View\Concerns\ManagesLayouts;
use Nitro\View\Concerns\ManagesLoops;
use Nitro\View\Concerns\ManagesStacks;
use Nitro\View\Concerns\ManagesStream;
use Nitro\View\Contracts\ComponentEngine;
use Nitro\View\Contracts\Engine as EngineContract;
use Nitro\View\Contracts\TagCompiler;
use Nitro\View\Contracts\TemplateCache;
use Nitro\View\Contracts\TemplateCompiler;
use Nitro\View\Contracts\ViewFinder;
use Nitro\View\Events\ViewEvent;
use Nitro\View\Events\ViewEvents;
use Nitro\View\FileViewFinder;
use Nitro\View\Support\DebugRenderPipeline;
use Nitro\View\Support\Htmlable;
use Nitro\View\Support\ViewExtensions;
use Nitro\View\Support\ViewManifest;
use Nitro\View\View;
use RuntimeException;

/**
 * Renders Blade templates: resolves a name to a file, executes its compiled
 * form, and resolves the layout, sections and stacks it declared.
 *
 * A compiled template runs in this object's scope, so most of this class exists
 * to be called from inside a template rather than from outside.
 */
class CompilerEngine implements EngineContract, ResetsBetweenRequests, ReceivesDispatcher
{
    use DispatchesEvents;

    use ManagesLayouts;
    use ManagesStacks;
    use ManagesFragments;
    use ManagesLoops;
    use ManagesStream;

    /**
     * Template file extensions, in search order, without the dot.
     *
     * @var array<int, string>
     */
    protected array $extensions;

    /** Whether the application is in debug mode. */
    protected bool $debug;

    /** Resolves view names to template files. */
    protected ViewFinder $finder;

    /** Whether to append the inline render diagnostic to top-level output. */
    protected bool $debugRender = false;

    /**
     * Whether each view declares `@stream`, remembered for the process rather
     * than the render, since a template's own text cannot change beneath it.
     *
     * @var array<string, bool>
     */
    protected array $streamViewCache = [];

    /**
     * All per-render transient state, replaced per top-level render so nothing
     * leaks between renders or between worker requests.
     */
    protected RenderContext $context;

    /**
     * @param TemplateCache    $templateCache Compiled templates and their freshness.
     * @param ComponentEngine  $components    Renders `<x-…>` components.
     * @param TemplateCompiler $compiler      Blade source to PHP.
     * @param TagCompiler      $tagCompiler   Component tags to directives.
     */
    public function __construct(
        protected readonly TemplateCache $templateCache,
        protected readonly ComponentEngine $components,
        protected readonly TemplateCompiler $compiler,
        protected readonly TagCompiler $tagCompiler,
        PathRegistry $paths,
        ConfigRepository $config,
        ?ViewFinder $finder = null,
    ) {
        $this->extensions = ViewExtensions::from($config);
        $this->debug      = (bool) $config->get('app.debug');
        $this->context    = new RenderContext();
        $this->finder     = $finder ?? new FileViewFinder($paths->views(), $this->extensions);

        if ($config->get('view.debug_render')) {
            $this->debugRender = true;
            DebugRenderPipeline::enable();
        }
    }

    // ─── Public rendering API ─────────────────────────────────

    /**
     * Render a view to HTML with optional layout inheritance.
     *
     * Every diagnostic below is gated at its call site, so a request with
     * debugging off pays one bool read rather than building the payloads.
     *
     * @param string               $view View name in dot notation (e.g. 'pages.home')
     * @param array<string, mixed> $data Variables to pass to the template
     * @return string Rendered HTML
     * @throws RuntimeException If template is not found or execution throws
     */
    public function render(string $view, array $data = []): string
    {
        $isTopLevel = ($this->context->renderCount === 0);

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

        /**
         * Emit point — view.rendering
         *
         * Before a template is compiled and executed. Fires for every view,
         * including each partial and component a page pulls in — so a single
         * page raises it many times, and renderCount says how deep this one
         * is. Zero means the page itself.
         * Payload: {@see ViewEvent}.
         */
        $this->eventLazy(
            ViewEvents::RENDERING,
            fn (): ViewEvent => new ViewEvent($view, $this->context->renderCount),
        );

        $result = ($this->debugRender && $isTopLevel)
            ? $this->debugRender($view, $data)
            : $this->renderFromFile($view, $data);

        /**
         * Emit point — view.rendered
         *
         * After the template produced its markup, with how much of it there
         * is. Pairs with view.rendering; a template that threw raises only the
         * first of the two.
         * Payload: {@see ViewEvent}.
         */
        $this->eventLazy(
            ViewEvents::RENDERED,
            fn (): ViewEvent => new ViewEvent($view, $this->context->renderCount, strlen($result)),
        );

        if (DebugRenderPipeline::isEnabled()) {
            DebugRenderPipeline::exit('render', ['output_length' => strlen($result)]);

            if ($isTopLevel && $this->debugRender) {
                DebugRenderPipeline::save();
            }
        }

        return $result;
    }

    /**
     * Determine whether a view uses `@stream`.
     *
     * Answered from the in-memory cache, then the build-time manifest, then a
     * live probe of the source. A fragment request never streams, so the
     * per-view verdict is cached and the request is only consulted for the
     * few views that declare it.
     */
    protected function isStreamView(string $view): bool
    {
        $isStream = $this->streamViewCache[$view] ??= $this->computeStreamView($view);
        if (! $isStream) {
            return false;
        }

        $container = app();
        if ($container->has('request')) {
            $request = $container->resolve('request');
            if (! empty($request->query('_fragment'))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The view's declared streaming status, ignoring the per-request override.
     *
     * The manifest is trusted only while it is at least as new as the source,
     * so a view edited after `optimize` is probed rather than misrouted.
     */
    private function computeStreamView(string $view): bool
    {
        $templateFile = $this->getTemplatePath($view);

        $manifestVerdict = ViewManifest::isStream($view);
        if ($manifestVerdict !== null && ViewManifest::isFresh($templateFile)) {
            return $manifestVerdict;
        }

        $firstBytes = @file_get_contents($templateFile, false, null, 0, 256);
        if ($firstBytes === false) {
            return false;
        }
        return str_contains($firstBytes, '@stream');
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
        } catch (\Throwable $templateError) {
            if ($this->context->currentFill !== null) {
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                $this->context->currentFill = null;
            }
            $this->endStream();

            throw new \RuntimeException(
                "Stream template error: " . $templateError->getMessage(),
                0,
                $templateError
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

    // ─── Cache delegation ─────────────────────────────────────

    /** Delete all compiled template files. */
    public function clearCache(): void
    {
        $this->templateCache->clear();
    }

    /**
     * Delete the compiled file for a single view.
     *
     * Resolves the name first, because the compiled form is keyed by the
     * template's path — clearing by name would miss it. A name that resolves
     * to nothing has nothing compiled, so it is not an error.
     */
    public function clearViewCache(string $view): void
    {
        try {
            $this->templateCache->clearView($this->getTemplatePath($view));
        } catch (RuntimeException) {
        }
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

   
   
    // ─── Internal rendering ───────────────────────────────────

    /**
     * Resolve view path, get compiled file, execute, and optionally render parent layout.
     *
     * The render depth and parent view are restored in a `finally` because this
     * object outlives the request: a leaked depth would make the next top-level
     * render look nested, skip {@see flushState()} and carry sections across.
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

                /*
                 * Values the page declared for itself reach the layout, which is
                 * where a title written at the top of a document is read. What
                 * the caller passed still wins.
                 */
                $output = $this->renderFromFile($parentView, $data + $this->context->pageData);
            }

            if ($debug) {
                DebugRenderPipeline::exit('renderFromFile', [
                    'view' => $view,
                    'output_length' => strlen($output),
                ]);
            }

            return $output;
        } finally {
            $this->context->parentView = $previousParentView;
            $this->context->renderCount--;
        }
    }

    /**
     * Run a compiled PHP file with data as variables; return captured output.
     *
     * Uses extract(EXTR_SKIP) and include so $this inside the template resolves
     * to this CompilerEngine instance — making all directive methods available.
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
        } catch (\Throwable $templateError) {
            ob_end_clean();
            throw new RuntimeException(
                "Error executing template: " . $templateError->getMessage() .
                    "\nFile: " . $compiledFile,
                0,
                $templateError
            );
        }

        return (string) ob_get_clean();
    }

    /**
     * Register directories a namespace resolves against, searched in order.
     *
     * @param string|array<int, string> $paths
     */
    public function addNamespace(string $namespace, string|array $paths): void
    {
        $this->finder->addNamespace($namespace, $paths);
    }

    /**
     * Register directories ahead of a namespace's existing ones.
     *
     * @param string|array<int, string> $paths
     */
    public function prependNamespace(string $namespace, string|array $paths): void
    {
        $this->finder->prependNamespace($namespace, $paths);
    }

    /**
     * Replace the directories a namespace resolves against.
     *
     * @param string|array<int, string> $paths
     */
    public function replaceNamespace(string $namespace, string|array $paths): void
    {
        $this->finder->replaceNamespace($namespace, $paths);
    }

    /**
     * Add a directory searched for views naming no namespace.
     */
    public function addLocation(string $path): void
    {
        $this->finder->addLocation($path);
    }

    /**
     * Add a directory searched before those already registered.
     */
    public function prependLocation(string $path): void
    {
        $this->finder->prependLocation($path);
    }

    /**
     * Get the directories searched for views naming no namespace, in order.
     *
     * @return array<int, string>
     */
    public function getLocations(): array
    {
        return $this->finder->getLocations();
    }

    /**
     * Resolve a view name to its absolute filesystem path.
     *
     * @throws RuntimeException When nothing matches, or the namespace is unknown.
     */
    protected function getTemplatePath(string $view): string
    {
        return $this->finder->find($view);
    }

    // ─── Debug ────────────────────────────────────────────────

    /**
     * Render and append an HTML comment with timing and cache metadata.
     */
    protected function debugRender(string $view, array $data = []): string
    {
        $startTime    = microtime(true);
        $output       = $this->renderFromFile($view, $data);
        $elapsed      = round((microtime(true) - $startTime) * 1000, 2);
        $templateFile = $this->getTemplatePath($view);
        $compiledFile = $this->templateCache->getCacheFilePath($templateFile);

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





    // ─── Component rendering — delegates to ComponentRenderer ───

    /**
     * Render a self-closing component tag.
     *
     * @param array<string, mixed> $attributes
     */
    public function renderComponent(string $name, array $attributes = [], string $slot = ''): void
    {
        $this->components->renderSelfClosing($name, $attributes, $slot);
    }

    /**
     * Open a component and begin capturing its slot.
     *
     * @param array<string, mixed> $attributes
     */
    public function startComponent(string $name, array $attributes = []): void
    {
        $this->components->start($name, $attributes);
    }

    /**
     * Close the open component and return its rendered output.
     */
    public function endComponent(): string
    {
        return $this->components->end();
    }

    /**
     * Begin capturing a named slot on the open component.
     */
    public function startNamedSlot(string $name): void
    {
        $this->components->startNamedSlot($name);
    }

    /**
     * Close the open named slot.
     */
    public function endNamedSlot(): void
    {
        $this->components->endNamedSlot();
    }

    /**
     * Get the values an `@aware` component inherits from its parent.
     *
     * @param  array<int, string> $keys
     * @return array<string, mixed>
     */
    public function getAwareData(array $keys): array
    {
        return $this->components->getAwareData($keys);
    }

    /**
     * Merge a component's declared props with the data it was given.
     *
     * @param  array<string, mixed> $propDefaults
     * @param  array<string, mixed> $componentData
     * @return array<string, mixed>
     */
    public function resolveComponentProps(array $propDefaults, array $componentData): array
    {
        return $this->components->resolveComponentProps($propDefaults, $componentData);
    }













    /**
     * Render one `@fragment` of a view.
     *
     * @param  array<string, mixed> $data
     * @throws \RuntimeException When the view declares no such fragment.
     */
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

    /**
     * Render several fragments of a view as one response.
     *
     * Every fragment after the first is marked for out-of-band swapping, so a
     * single response can update more than one region of the page.
     *
     * @param  array<int, string>   $fragments
     * @param  array<string, mixed> $data
     * @throws \RuntimeException When the view declares no such fragment.
     */
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




    /**
     * Render a {@see View} value object.
     */
    public function renderView(View $view): string
    {
        return $this->render($view->name(), $view->getData());
    }

    /**
     * Escape a value for output.
     *
     * @deprecated Compiled templates now emit \nitro_e() directly. Kept so
     *             previously compiled templates keep working.
     */
    public function e(mixed $value): string
    {
        return \nitro_e($value);
    }


    /**
     * Compile and render Blade source that has no file behind it.
     *
     * Goes through a temp file so `$this` inside the template is this renderer.
     *
     * @param  array<string, mixed> $data
     * @return string
     */
    public function renderString(string $blade, array $data = []): string
    {
        $compiled = $this->tagCompiler->compile($blade);
        $compiled = $this->compiler->compile($compiled);

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

    /**
     * Determine whether an `@once` block with this id has already run.
     */
    public function hasRenderedOnce(string $id): bool
    {
        return isset($this->context->renderedOnce[$id]);
    }

    /**
     * Record that an `@once` block with this id has run.
     */
    public function markRenderedOnce(string $id): void
    {
        $this->context->renderedOnce[$id] = true;
    }

    /**
     * Begin capturing output destined for a teleport target.
     */
    public function startTeleport(string $target): void
    {
        $this->context->currentTeleport = $target;
        ob_start();
    }

    /**
     * Stop capturing and append what was captured to its target's buffer.
     */
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

    /**
     * Get everything teleported to a target.
     */
    public function yieldTeleport(string $target): string
    {
        return $this->context->teleportBuffers[$target] ?? '';
    }

    /**
     * Discard every teleport buffer.
     */
    public function clearTeleports(): void
    {
        $this->context->teleportBuffers = [];
        $this->context->currentTeleport = null;
    }

    // ─── What compiled @include directives call ───────────

    /**
     * Render an included view with the including template's variables in scope.
     *
     * Told apart by argument count: `@include('v')` passes the scope as $data,
     * `@include('v', [...])` passes data then scope.
     *
     * @param array<string, mixed> $data Extra data from the call site.
     * @param array<string, mixed> $vars The including template's scope.
     */
    public function renderInclude(string $view, array $data = [], array $vars = []): string
    {
        if (func_num_args() === 2 && ! empty($data)) {
            return $this->renderPartial($view, $data);
        }

        return $this->renderPartial($view, array_merge($vars, $data));
    }

    /**
     * Render an included view only when the condition holds.
     *
     * @param array<string, mixed> $data Extra data given at the call site.
     * @param array<string, mixed> $vars The including template's scope.
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
     * Emptiness is detected on the first foreach step, so a generator is not
     * exhausted before the loop starts.
     *
     * @param string   $view    View to render per item.
     * @param iterable $data    Items to iterate.
     * @param string   $itemVar Variable name for each item.
     * @param string   $empty   View to render when there are none.
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
     * @param array<int|string, mixed> $classes
     */
    public function toCssClasses(array $classes): string
    {
        return Arr::toCssClasses($classes);
    }

    /**
     * Convert an array of style conditions to a CSS style string.
     *
     * @param array<int|string, mixed> $styles
     */
    public function toCssStyles(array $styles): string
    {
        return Arr::toCssStyles($styles);
    }

    /**
     * Reset all per-render state by swapping in a fresh context.
     *
     * Called at the start of every top-level render, which is what makes the
     * renderer safe to share as a singleton.
     */
    public function flushState(): void
    {
        $this->context = new RenderContext();
    }

    /**
     * Discard per-render state between worker requests.
     *
     * Without this a `@push` from one request lands in the next one's `@stack`.
     */
    public function resetBetweenRequests(): void
    {
        $this->flushState();
    }

    /**
     * Turn the inline render diagnostic on for this process.
     */
    public function enableRenderDebug(): void
    {
        $this->debugRender = true;
        DebugRenderPipeline::enable();
    }

    /**
     * Turn the inline render diagnostic off again.
     */
    public function disableRenderDebug(): void
    {
        $this->debugRender = false;
        DebugRenderPipeline::disable();
    }

    /**
     * Render a compiled template with `$this` bound to another object.
     *
     * A component's template refers to the component's own properties, so the
     * include happens inside a closure bound to it.
     *
     * @param array<string, mixed> $data
     * @param object               $context What `$this` will be in the template.
     */
    public function renderWithContext(string $view, array $data, object $context): string
    {
        $this->context->renderCount++;

        try {
            $templateFile = $this->getTemplatePath($view);
            $compiledFile = $this->templateCache->resolve($templateFile, $view);

            $executor = function (string $__compiledFile, array $__data) {
                extract($__data, EXTR_SKIP);
                ob_start();
                try {
                    include $__compiledFile;
                } catch (\Throwable $templateError) {
                    ob_end_clean();
                    throw new \RuntimeException(
                        "Error executing template: " . $templateError->getMessage() .
                            "\nFile: " . $__compiledFile,
                        0,
                        $templateError
                    );
                }
                return (string) ob_get_clean();
            };

            $bound = \Closure::bind($executor, $context, get_class($context));

            return $bound($compiledFile, $data);
        } finally {
            $this->context->renderCount--;
        }
    }
}
