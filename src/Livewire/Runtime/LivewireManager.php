<?php

namespace Nitro\Livewire\Runtime;

use Closure;
use Nitro\Container\Contracts\CallableInvoker;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\View\Contracts\Engine;
use Nitro\Http\Response;
use Nitro\Livewire\Compilation\SingleFileComponent;
use Nitro\Livewire\Component;
use Nitro\Livewire\Http\AssetController;
use Nitro\Livewire\Snapshot\Checksum;
use Nitro\Livewire\Snapshot\Snapshotter;
use Nitro\Livewire\Snapshot\Synthesizers\Synth;
use Nitro\Livewire\Snapshot\Synthesizers\SynthManager;

/**
 * The layer's front door — a THIN facade over the runtime pieces.
 *
 * The service provider binds this as the 'livewire' service and the Blade
 * directives, the update route and the asset route all call into it, so its
 * surface is the layer's public API and stays put. Every method below simply
 * delegates to the collaborator that owns the concern:
 *
 *   ComponentRegistry — what a component name means, and making one
 *   Mounter           — the first render (inline, page, lazy placeholder)
 *   UpdatePipeline    — the commit phase sequence
 *   Snapshotter       — the wire boundary (state in, state out, signed)
 *   AssetController   — the client runtime and its tags
 *
 * The collaborators are built lazily so nothing touches config() or the
 * container before the application has booted.
 */
class LivewireManager
{
    /**
     * Middleware that must also run on the update endpoint.
     *
     * A Livewire update posts to /livewire/update, not to the route that
     * rendered the page, so the page's own route middleware does not run on it.
     * Anything a component reads out of ambient request state — a tenant or
     * organisation resolved from the URL, a locale, an impersonation context —
     * is therefore unset from the second request onward. The screen renders
     * perfectly and then refuses every button on it, with no error anywhere.
     *
     * Registering middleware here puts it on the update route too:
     *
     *     LivewireManager::addPersistentMiddleware([EnsureOrganisationMember::class]);
     *
     * Call it from a provider's register(), which runs before every boot() and
     * so cannot land after this layer has registered its routes.
     *
     * Static because it is boot-time configuration, identical for every request
     * a worker serves.
     *
     * @var array<int, string>
     */
    private static array $persistentMiddleware = [];

    /**
     * Add middleware to the Livewire update and upload routes.
     *
     * @param  array<int, string>|string  $middleware  Alias or class name.
     */
    public static function addPersistentMiddleware(array|string $middleware): void
    {
        foreach ((array) $middleware as $name) {
            if (!in_array($name, self::$persistentMiddleware, true)) {
                self::$persistentMiddleware[] = $name;
            }
        }
    }

    /** @return array<int, string> */
    public static function persistentMiddleware(): array
    {
        return self::$persistentMiddleware;
    }

    /** Drop the registered list. For tests, which must not leak into each other. */
    public static function flushPersistentMiddleware(): void
    {
        self::$persistentMiddleware = [];
    }

    protected ?ComponentRegistry $registry = null;
    protected ?SynthManager $synths = null;
    protected ?Checksum $checksum = null;
    protected ?Snapshotter $snapshotter = null;
    protected ?Renderer $renderer = null;
    protected ?ActionInvoker $actions = null;
    protected ?Mounter $mounter = null;
    protected ?UpdatePipeline $pipeline = null;
    protected ?AssetController $assets = null;

    /**
     * @param ClassResolver $resolver Builds a component from the class its
     *        name maps to — a type only the application knows.
     * @param Closure(): Engine $engine Built only when a full page needs its
     *        layout rendered.
     */
    public function __construct(
        protected ClassResolver $resolver,
        protected CallableInvoker $invoker,
        protected ConfigRepository $config,
        protected Closure $engine,
    ) {}

    // ─── Collaborators ──────────────────────────────────────────────────────

    /** The name ↔ class registry. */
    public function registry(): ComponentRegistry
    {
        return $this->registry ??= new ComponentRegistry($this->resolver, $this->config);
    }

    /** The synthesizer registry, lazily built with the default synths. */
    public function synths(): SynthManager
    {
        return $this->synths ??= SynthManager::default();
    }

    /** The snapshot signer, keyed by the application key. */
    public function checksum(): Checksum
    {
        return $this->checksum ??= new Checksum(
            (string) ($this->config->get('app.key') ?: 'livewire-insecure-dev-key')
        );
    }

    /** The wire boundary: snapshot() / fromSnapshot(). */
    public function snapshotter(): Snapshotter
    {
        return $this->snapshotter ??= new Snapshotter($this->registry(), $this->synths(), $this->checksum());
    }

    /** Component → HTML, plus wrapRoot/extractRegion. */
    public function renderer(): Renderer
    {
        return $this->renderer ??= new Renderer();
    }

    /** The guard every browser-called method passes through. */
    public function actions(): ActionInvoker
    {
        return $this->actions ??= new ActionInvoker($this->invoker);
    }

    /** The initial-render pipeline. */
    public function mounter(): Mounter
    {
        return $this->mounter ??= new Mounter(
            $this->invoker,
            $this->engine,
            $this->registry(),
            $this->renderer(),
            $this->snapshotter(),
            $this->config,
        );
    }

    /** The commit pipeline. */
    public function pipeline(): UpdatePipeline
    {
        return $this->pipeline ??= new UpdatePipeline(
            $this->invoker, $this->snapshotter(), $this->renderer(), $this->actions()
        );
    }

    /** The client runtime and its asset tags. */
    public function assets(): AssetController
    {
        return $this->assets ??= new AssetController();
    }

    // ─── Registration ───────────────────────────────────────────────────────

    /** Register an application-provided synthesizer for a custom property type. */
    public function registerSynth(Synth $synth): void
    {
        $this->synths()->register($synth);
    }

    /** Register a component under an explicit name. */
    public function component(string $name, string $class): void
    {
        $this->registry()->component($name, $class);
    }

    /** Resolve a component name to its class. */
    public function resolveClass(string $name): string
    {
        return $this->registry()->resolveClass($name);
    }

    /** Resolve a name to a component class, or null if none exists (no throw). */
    public function resolveClassOrNull(string $name): ?string
    {
        return $this->registry()->resolveClassOrNull($name);
    }

    /** Instantiate a component by name (class-based, else single-file). */
    public function makeComponent(string $name): Component
    {
        return $this->registry()->make($name);
    }

    /** The single-file component compiler (app SFCs under resources/views/livewire). */
    public function singleFile(): SingleFileComponent
    {
        return $this->registry()->singleFile();
    }

    // ─── Rendering ──────────────────────────────────────────────────────────

    /**
     * First render of a component to HTML with its initial snapshot embedded on
     * the root element.
     */
    public function mount(string $name, array $params = [], array $slots = []): string
    {
        return $this->mounter()->mount($name, $params, $slots);
    }

    /** Render a component as a full page, inside its #[Layout]. */
    public function page(string $name, array $params = []): string
    {
        return $this->mounter()->page($name, $params);
    }

    // ─── The wire boundary ──────────────────────────────────────────────────

    /**
     * Dehydrate a component into its signed transport snapshot.
     *
     * @return array{data: array, memo: array, checksum: string}
     */
    public function snapshot(Component $component, array $extraMemo = []): array
    {
        return $this->snapshotter()->snapshot($component, $extraMemo);
    }

    /** Hydrate a component from a received snapshot (checksum-verified). */
    public function fromSnapshot(array $snapshot): Component
    {
        return $this->snapshotter()->fromSnapshot($snapshot);
    }

    /**
     * Handle a batch of update commits from the browser.
     *
     * @param array $payload  { components: [ { snapshot, updates, calls } ] }
     * @return array          { components: [ { snapshot, effects: { html } } ] }
     */
    public function update(array $payload): array
    {
        return $this->pipeline()->update($payload);
    }

    // ─── Assets ─────────────────────────────────────────────────────────────

    /** Absolute path to the client runtime bundled inside the framework package. */
    public function scriptPath(): string
    {
        return $this->assets()->scriptPath();
    }

    /** Serve the client runtime for the /livewire/livewire.js route. */
    public function scriptResponse(): Response
    {
        return $this->assets()->scriptResponse();
    }

    /** The <script> tag(s) that boot the Livewire client. */
    public function scripts(): string
    {
        return $this->assets()->scripts();
    }

    /** The <style> tag(s) for Livewire (e.g. wire:loading / wire:cloak). */
    public function styles(): string
    {
        return $this->assets()->styles();
    }
}
