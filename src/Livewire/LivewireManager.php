<?php

namespace Nitro\Livewire;

use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Http\Response;
use Nitro\Livewire\Attributes\Lazy;
use Nitro\Livewire\Attributes\RenderRegion;
use Nitro\Livewire\Synthesizers\Synth;
use Nitro\Livewire\Synthesizers\SynthManager;
use Nitro\Validation\ValidationException;
use RuntimeException;

/**
 * Central registry and entry point for the Livewire layer: resolves component
 * names to classes, performs the first (mount) render, and handles update
 * commits from the browser. The service provider binds this as the 'livewire'
 * service; the Blade directives and the /livewire/update route call into it.
 */
class LivewireManager
{
    /** @var array<string, class-string> Registered name => component class. */
    protected array $components = [];

    /** Namespace app components are resolved from by convention. */
    protected string $namespace = 'App\\Livewire\\';

    /** Encodes/decodes non-scalar property values (models, collections, files, enums). */
    protected ?SynthManager $synths = null;

    public function __construct(protected ContainerInterface $container)
    {
        $this->namespace = rtrim((string) config('livewire.class_namespace', 'App\\Livewire'), '\\') . '\\';
    }

    /** The synthesizer registry, lazily built with the default synths. */
    public function synths(): SynthManager
    {
        return $this->synths ??= SynthManager::default();
    }

    /** Register an application-provided synthesizer for a custom property type. */
    public function registerSynth(Synth $synth): void
    {
        $this->synths()->register($synth);
    }

    /** Register a component under an explicit name. */
    public function component(string $name, string $class): void
    {
        $this->components[$name] = $class;
    }

    /**
     * Resolve a component name to its class — an explicit registration first,
     * then by convention (e.g. 'user-profile' → App\Livewire\UserProfile,
     * 'nav.bar' → App\Livewire\Nav\Bar).
     *
     * @return class-string
     */
    public function resolveClass(string $name): string
    {
        $class = $this->resolveClassOrNull($name);

        if ($class === null) {
            throw new RuntimeException("Livewire component [{$name}] not found.");
        }

        return $class;
    }

    /** Resolve a name to a component class, or null if none exists (no throw). */
    public function resolveClassOrNull(string $name): ?string
    {
        if (isset($this->components[$name])) {
            return $this->components[$name];
        }

        $segments = array_map(
            static fn(string $part): string => str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $part))),
            explode('.', $name)
        );

        $class = $this->namespace . implode('\\', $segments);

        return class_exists($class) ? $class : null;
    }

    /**
     * Instantiate a component by name — a class-based component when one exists,
     * otherwise a single-file component. Assigns a fresh identity; callers that
     * hydrate a snapshot overwrite it with the stored id.
     */
    public function makeComponent(string $name): Component
    {
        $class = $this->resolveClassOrNull($name);

        if ($class !== null) {
            /** @var Component $component */
            $component = $this->container->make($class);
            $component->assertPropertiesAreTyped();
            $component->setContext($this->generateId(), $name);

            return $component;
        }

        $sfc = $this->singleFile()->resolve($name);

        if ($sfc !== null) {
            $component = require $sfc['class'];
            if (! $component instanceof Component) {
                throw new RuntimeException("Single-file component [{$name}] must be a Nitro\\Livewire\\Component.");
            }
            $component->assertPropertiesAreTyped();
            $component->setContext($this->generateId(), $name);
            $component->setInlineView($sfc['view']);

            return $component;
        }

        throw new RuntimeException("Livewire component [{$name}] not found (no class or single-file component).");
    }

    /** The single-file component compiler (app SFCs under resources/views/livewire). */
    public function singleFile(): SingleFileComponent
    {
        return $this->singleFile ??= new SingleFileComponent(
            (string) config('livewire.view_path', base_path('resources/views/livewire')),
            storage_path('cache/livewire-sfc')
        );
    }

    protected ?SingleFileComponent $singleFile = null;

    /**
     * First render of a component to HTML with its initial snapshot embedded on
     * the root element.
     */
    public function mount(string $name, array $params = [], array $slots = []): string
    {
        $component = $this->makeComponent($name);

        if ($slots !== []) {
            $component->setSlots($slots);
        }

        return $this->renderNew($component, $params);
    }

    /** Boot, mount (or defer for lazy), render, and wrap a fresh component instance. */
    protected function renderNew(Component $component, array $params): string
    {
        $this->callHook($component, 'boot');

        // Lazy: paint a placeholder now and defer mount()/render to a follow-up
        // commit. The mount params ride along in the (signed) memo.
        if ($this->isLazy($component)) {
            $snapshot = $this->snapshot($component, ['lazy' => true, 'lazyParams' => $params]);

            return $this->wrapRoot($this->renderPlaceholder($component), $component->getId(), $snapshot);
        }

        if (method_exists($component, 'mount')) {
            $this->container->call([$component, 'mount'], $params);
        }

        $component->initializeUrlBindings();
        $this->callHook($component, 'booted');

        // Initial render: all islands render (or their placeholder if lazy/defer).
        $component->beginIslandRender(true);

        $html = $this->renderComponent($component);
        $snapshot = $this->snapshot($component);

        return $this->wrapRoot($html, $component->getId(), $snapshot);
    }

    /** Whether a component opts into lazy loading via #[Lazy]. */
    protected function isLazy(Component $component): bool
    {
        return (new \ReflectionObject($component))->getAttributes(Lazy::class) !== [];
    }

    /** The placeholder HTML shown before a lazy component loads. */
    protected function renderPlaceholder(Component $component): string
    {
        if (method_exists($component, 'placeholder')) {
            return $component->placeholder();
        }

        return '<div class="animate-pulse rounded-lg bg-slate-100 p-6 dark:bg-slate-800">&nbsp;</div>';
    }

    /**
     * Render a component as a full page — its HTML injected into the layout
     * declared by #[Layout(...)] (or the configured default). Used for routed
     * full-page components (Route::livewire(...)).
     */
    public function page(string $name, array $params = []): string
    {
        $component = $this->makeComponent($name);
        $layout = $this->layoutFor($component);
        $html = $this->renderNew($component, $params);

        if ($layout === null) {
            return $html;
        }

        return app(\Nitro\View\Contracts\ViewEngine::class)->render('livewire::page', [
            '__layout'  => $layout[0],
            '__section' => $layout[1],
            '__slot'    => $html,
        ]);
    }

    /** Resolve a component's layout: #[Layout] attribute, else config default. */
    protected function layoutFor(Component $component): ?array
    {
        $attributes = (new \ReflectionObject($component))->getAttributes(\Nitro\Livewire\Attributes\Layout::class);

        if ($attributes !== []) {
            $layout = $attributes[0]->newInstance();
            return [$layout->layout, $layout->section];
        }

        $default = config('livewire.layout');

        return $default ? [$default, config('livewire.layout_section', 'content')] : null;
    }

    /**
     * Dehydrate a component into its signed transport snapshot: state, identity
     * memo, and an integrity checksum.
     *
     * @return array{data: array, memo: array, checksum: string}
     */
    public function snapshot(Component $component, array $extraMemo = []): array
    {
        $data = $this->synths()->dehydrate($component->all());
        $memo = [
            'id'        => $component->getId(),
            'name'      => $component->getName(),
            'errors'    => $component->errorsToArray(),
            'listeners' => $component->listeners(),
        ];

        if (($url = $component->urlBindings()) !== []) {
            $memo['url'] = $url;
        }

        if (($slots = $component->slotsToArray()) !== []) {
            $memo['slots'] = $slots;
        }

        $memo = array_merge($memo, $extraMemo);

        return [
            'data'     => $data,
            'memo'     => $memo,
            'checksum' => $this->checksum()->generate($data, $memo),
        ];
    }

    /**
     * Hydrate a component from a received snapshot: verify the checksum, rebuild
     * the instance for the snapshot's component, and restore its public state.
     */
    public function fromSnapshot(array $snapshot): Component
    {
        $this->checksum()->verify($snapshot);

        $memo = $snapshot['memo'] ?? [];
        $name = (string) ($memo['name'] ?? '');

        $component = $this->makeComponent($name);
        $component->setContext((string) ($memo['id'] ?? $this->generateId()), $name);
        $component->setErrors((array) ($memo['errors'] ?? []));

        // Restore slots (parent-provided HTML) so the child re-renders with them.
        if (! empty($memo['slots'])) {
            $component->setSlots(array_map(
                static fn(string $html): Slot => new Slot($html),
                (array) $memo['slots']
            ));
        }

        // Expand synth tuples (models, collections, files, enums) back to objects.
        $data = $this->synths()->hydrate($snapshot['data'] ?? []);

        foreach ($data as $key => $value) {
            $component->setProperty($key, $value);
        }

        return $component;
    }

    /** The snapshot signer, keyed by the application key. */
    protected function checksum(): Checksum
    {
        return $this->checksum ??= new Checksum(
            (string) (config('app.key') ?: 'livewire-insecure-dev-key')
        );
    }

    protected ?Checksum $checksum = null;

    /** Render the component, firing the rendering/rendered lifecycle hooks around it. */
    protected function renderComponent(Component $component): string
    {
        $this->callHook($component, 'rendering');
        $html = $component->render();
        $this->callHook($component, 'rendered', $html);

        return $html;
    }

    /** Invoke an optional lifecycle hook if the component defines it. */
    protected function callHook(Component $component, string $hook, mixed ...$args): void
    {
        if (method_exists($component, $hook)) {
            $component->{$hook}(...$args);
        }
    }

    /** A short random component instance id. */
    protected function generateId(): string
    {
        return substr(bin2hex(random_bytes(12)), 0, 20);
    }

    /**
     * Inject wire:id + wire:snapshot onto the component's single root element so
     * the client can hydrate and re-render it.
     */
    protected function wrapRoot(string $html, string $id, array $snapshot): string
    {
        $attrs = ' wire:id="' . $id . '"'
            . ' wire:snapshot="' . htmlspecialchars(json_encode($snapshot), ENT_QUOTES) . '"';

        return preg_replace_callback(
            '/<[a-zA-Z][a-zA-Z0-9-]*/',
            static fn(array $m): string => $m[0] . $attrs,
            $html,
            1
        ) ?? $html;
    }

    /** Lifecycle methods that are never callable as actions from the browser. */
    private const RESERVED_METHODS = [
        'render', 'mount', 'view', 'all', 'setproperty', 'setcontext',
        'getid', 'getname', 'boot', 'booted', 'hydrate', 'dehydrate',
        'updating', 'updated', 'rendering', 'rendered',
    ];

    /**
     * Handle a batch of update commits from the browser. Each commit hydrates its
     * component (checksum-verified), applies property updates and method calls,
     * re-renders, and returns a fresh signed snapshot + the re-rendered HTML.
     *
     * @param array $payload  { components: [ { snapshot, updates, calls } ] }
     * @return array          { components: [ { snapshot, effects: { html } } ] }
     */
    public function update(array $payload): array
    {
        $components = [];

        foreach (($payload['components'] ?? []) as $commit) {
            $components[] = $this->handleCommit($commit);
        }

        return ['components' => $components];
    }

    /** Process a single component commit → { snapshot, effects }. */
    protected function handleCommit(array $commit): array
    {
        $snapshot = $commit['snapshot'] ?? [];
        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true) ?: [];
        }

        $memo = $snapshot['memo'] ?? [];
        $component = $this->fromSnapshot($snapshot);

        $this->callHook($component, 'boot');
        $this->callHook($component, 'hydrate');

        $calls = $commit['calls'] ?? [];

        // Lazy load: the deferred mount runs now, then the real view renders in
        // place of the placeholder. The synthetic __lazyLoad call is consumed here.
        if (! empty($memo['lazy'])) {
            if (method_exists($component, 'mount')) {
                $this->container->call([$component, 'mount'], (array) ($memo['lazyParams'] ?? []));
            }
            $component->initializeUrlBindings();
            $calls = array_values(array_filter(
                $calls,
                static fn(array $c): bool => ($c['method'] ?? '') !== '__lazyLoad'
            ));
        }

        // Island target: from where the interaction originated (commit island) or
        // an __loadIsland trigger for a lazy/deferred island. Everything else is
        // a normal re-render in which all islands freeze.
        $islandTarget = $commit['island'] ?? null;
        $calls = array_values(array_filter($calls, static function (array $c) use (&$islandTarget): bool {
            if (($c['method'] ?? '') === '__loadIsland') {
                $islandTarget = $c['params'][0] ?? $islandTarget;
                return false;
            }
            return true;
        }));

        foreach (($commit['updates'] ?? []) as $key => $value) {
            // #[Locked] properties may not be changed from the browser — reject a
            // wire:model update or a forged `updates` entry that targets one.
            if ($component->isPropertyLocked($key)) {
                throw new \Nitro\Livewire\Exceptions\CannotUpdateLockedProperty($key);
            }

            $studly = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $key)));
            $this->callHook($component, 'updating', $key, $value);
            $this->callHook($component, 'updating' . $studly, $value);
            $component->setProperty($key, $value);
            $this->callHook($component, 'updated', $key, $value);
            $this->callHook($component, 'updated' . $studly, $value);
        }

        $lastAction = null;
        foreach ($calls as $call) {
            $method = (string) ($call['method'] ?? '');
            $this->callAction($component, $method, (array) ($call['params'] ?? []));
            if ($method !== '') {
                $lastAction = $method;
            }
        }

        if ($lastAction !== null) {
            $this->applyRenderAttributes($component, $lastAction);
        }

        $this->callHook($component, 'booted');

        // Re-render: only the targeted island renders; the rest freeze.
        $component->beginIslandRender(false, $islandTarget);

        // Render, then dehydrate — the snapshot reflects post-render state.
        $html = $this->renderComponent($component);
        $newSnapshot = $this->snapshot($component);
        $html = $this->wrapRoot($html, $component->getId(), $newSnapshot);

        $effects = [
            'html'       => $html,
            'dispatches' => $component->dispatchesToArray(),
        ];

        // Scope the response to one region — from #[RenderRegion]/renderRegion()
        // or from where the interaction originated (the commit's region).
        $region = $component->pullRegion() ?? ($commit['region'] ?? null);
        if ($region !== null && $region !== '') {
            $regionHtml = $this->extractRegion($html, (string) $region);
            if ($regionHtml !== null) {
                $effects['region'] = ['name' => $region, 'html' => $regionHtml];
                unset($effects['html']);
            }
        }

        return [
            'snapshot' => $newSnapshot,
            'effects'  => $this->withRedirect($component, $effects),
        ];
    }

    /** Append a redirect effect if the component requested one. */
    protected function withRedirect(Component $component, array $effects): array
    {
        if (($redirect = $component->redirectToArray()) !== null) {
            $effects['redirect'] = $redirect['url'];
            $effects['redirectUsingNavigate'] = $redirect['navigate'];
        }

        return $effects;
    }

    /**
     * Scope an action's re-render to a region when it declares #[RenderRegion]
     * — unless the action already called renderRegion() explicitly.
     */
    protected function applyRenderAttributes(Component $component, string $action): void
    {
        if ($component->pullRegion() !== null) {
            return;
        }

        try {
            $method = new \ReflectionMethod($component, $action);
        } catch (\ReflectionException) {
            return;
        }

        $regionAttributes = $method->getAttributes(RenderRegion::class);
        if ($regionAttributes !== []) {
            $component->renderRegion($regionAttributes[0]->newInstance()->name);
        }
    }

    /**
     * Pull a single <div wire:region="name">…</div> block out of rendered HTML,
     * depth-matching nested <div> tags. Returns null if the region isn't found.
     * Self-contained to the Livewire layer — unrelated to Nitro's @fragment.
     */
    protected function extractRegion(string $html, string $name): ?string
    {
        $marker = 'wire:region="' . $name . '"';
        $markerPos = strpos($html, $marker);
        if ($markerPos === false) {
            return null;
        }

        $start = strrpos(substr($html, 0, $markerPos), '<div');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $i = $start;
        $len = strlen($html);

        while ($i < $len) {
            $open = strpos($html, '<div', $i);
            $close = strpos($html, '</div>', $i);
            if ($close === false) {
                return null;
            }

            if ($open !== false && $open < $close) {
                $depth++;
                $i = $open + 4;
            } else {
                $depth--;
                $i = $close + 6;
                if ($depth === 0) {
                    return substr($html, $start, $i - $start);
                }
            }
        }

        return null;
    }

    /** Invoke a browser-called action, rejecting anything not a public component method. */
    protected function callAction(Component $component, string $method, array $params): void
    {
        if ($method === '' || ! $this->isCallableAction($component, $method)) {
            throw new RuntimeException(
                "Livewire method [{$method}] is not callable on component [{$component->getName()}]."
            );
        }

        try {
            $this->container->call([$component, $method], $params);
        } catch (ValidationException $e) {
            // Validation failures are recorded on the component's error bag; swallow
            // so the component re-renders inline with errors instead of 500-ing.
        }
    }

    /** A callable action is a public, non-static method declared on the component subclass. */
    protected function isCallableAction(Component $component, string $method): bool
    {
        if (! method_exists($component, $method) || in_array(strtolower($method), self::RESERVED_METHODS, true)) {
            return false;
        }

        $reflection = new \ReflectionMethod($component, $method);

        return $reflection->isPublic()
            && ! $reflection->isStatic()
            && $reflection->getDeclaringClass()->getName() !== Component::class;
    }

    /**
     * Absolute path to the client runtime bundled inside the framework package
     * (src/Livewire/dist/livewire.js). This is the single source of truth: the
     * runtime ships with nitro/framework and is served from here, so an app
     * never carries its own copy in public/ (which would drift per app).
     */
    public function scriptPath(): string
    {
        return __DIR__ . '/dist/livewire.js';
    }

    /**
     * Serve the client runtime as an HTTP response for the /livewire/livewire.js
     * route. The far-future `immutable` cache header means a browser fetches it
     * exactly once and never revalidates; the `?v=` query in scripts() busts
     * that cache only when the bundled file actually changes (e.g. a framework
     * upgrade), so there is no per-request PHP cost after the first hit.
     */
    public function scriptResponse(): Response
    {
        $path = $this->scriptPath();
        $body = is_file($path) ? (string) file_get_contents($path) : '';

        return new Response($body, 200, [
            'Content-Type'  => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /** The <script> tag(s) that boot the Livewire client. */
    public function scripts(): string
    {
        $config = json_encode([
            'updateUri' => config('livewire.update_uri', '/livewire/update'),
            'uploadUri' => config('livewire.upload_uri', '/livewire/upload'),
            'csrf'      => function_exists('csrf_token') ? csrf_token() : '',
            // wire:navigate hover-prefetch tuning (config/livewire.php → navigate).
            // hoverDelayMs: hover this long before prefetching (Livewire uses 60).
            // cacheTtl: default cache window for a bare `.hover` (e.g. "30s"); a
            // per-link `wire:navigate.hover="30s"` overrides it. "0s" = one-shot.
            'navigate'  => [
                'hoverDelayMs' => (int) config('livewire.navigate.hover_delay_ms', 60),
                'cacheTtl'     => (string) config('livewire.navigate.cache_ttl', '0s'),
            ],
        ], JSON_UNESCAPED_SLASHES);

        // Cache-bust by the bundled file's mtime: the URL changes only when the
        // runtime shipped in the package changes, so a framework upgrade forces
        // a refetch while the immutable header keeps unchanged files cached.
        // Served from the framework route below — not the app's public/ dir.
        $v = @filemtime($this->scriptPath()) ?: '1';

        return '<script>window.Livewire=window.Livewire||{};window.Livewire.config=' . $config . ';</script>'
            . '<script src="/livewire/livewire.js?v=' . $v . '" defer></script>';
    }

    /** The <style> tag(s) for Livewire (e.g. wire:loading / wire:cloak). */
    public function styles(): string
    {
        return '<style>[wire\\:cloak]{display:none!important}</style>';
    }
}
