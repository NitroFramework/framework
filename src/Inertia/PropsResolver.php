<?php

namespace Nitro\Inertia;

use Nitro\Http\Request;
use Nitro\Inertia\Contracts\Deferrable;
use Nitro\Inertia\Contracts\IgnoreFirstLoad;
use Nitro\Inertia\Contracts\Mergeable;
use Nitro\Inertia\Contracts\Onceable;
use Nitro\Inertia\Contracts\ProvidesInertiaProperties;
use Nitro\Inertia\Contracts\ProvidesInertiaProperty;
use Nitro\Inertia\Contracts\Rescuable;
use Nitro\Inertia\Props\AlwaysProp;
use Nitro\Inertia\Props\ScrollProp;
use Nitro\Inertia\Support\Header;
use Nitro\Inertia\Support\Setting;

/**
 * Decides which props a response actually carries, and evaluates them.
 *
 * This is where the protocol earns its keep. A full page load sends
 * everything; a partial reload sends only the props the client named, so the
 * closures behind the others are never called. Props that opt out of the first
 * load are held back and advertised instead, and props that merge are reported
 * so the client knows to append rather than replace.
 *
 * Nothing is resolved before it is known to be needed — that ordering is the
 * point, not an optimisation.
 */
class PropsResolver
{
    /** Props the client asked for by name, or null when it asked for all. */
    private ?array $only;

    /** Props the client asked to be left out, or null when it named none. */
    private ?array $except;

    private bool $isPartial;

    /** @var array<string, array<int, string>> group => prop paths */
    private array $deferredProps = [];

    /** @var array<int, string> */
    private array $mergeProps = [];

    /** @var array<int, string> */
    private array $deepMergeProps = [];

    /** @var array<string, array<int, string>> */
    private array $matchPropsOn = [];

    /** @var array<int, string> */
    private array $sharedPropKeys = [];

    /** @var array<int, string> */
    private array $prependProps = [];

    /** @var array<string, array<string, mixed>> */
    private array $scrollProps = [];

    /** @var array<string, array<string, mixed>> */
    private array $onceProps = [];

    /** Once-props the client says it already holds. */
    private array $loadedOnceProps = [];

    /** @var array<int, string> Props whose resolution failed and was contained. */
    private array $rescuedProps = [];

    public function __construct(private Request $request, private string $component)
    {
        /*
         * A partial reload is only partial for the component it names. A
         * client holding page A that asks for props while navigating to page B
         * gets all of B — otherwise B would render with A's prop list.
         */
        $this->isPartial = $request->header(Header::PARTIAL_COMPONENT) === $component;

        $this->only = $this->isPartial ? $this->parseHeader(Header::PARTIAL_ONLY) : null;
        $this->except = $this->isPartial ? $this->parseHeader(Header::PARTIAL_EXCEPT) : null;

        // Sent on every request, not just partial ones: the client holds these
        // across navigations, which is the point of them.
        $this->loadedOnceProps = $this->parseHeader(Header::EXCEPT_ONCE_PROPS) ?? [];
    }

    /**
     * The props for this response, and the metadata describing them.
     *
     * @param  array<array-key, mixed> $shared
     * @param  array<array-key, mixed> $props
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    public function resolve(array $shared, array $props): array
    {
        $shared = $this->expandProviders($shared);
        $props = $this->expandProviders($props);

        /*
         * Telling the client which props were shared lets it keep them across
         * a partial reload. An application that would rather not publish that
         * list can turn it off.
         */
        if ((bool) Setting::get('inertia.expose_shared_prop_keys', true)) {
            $this->sharedPropKeys = array_values(array_unique(array_map(
                static fn (string $key): string => str_contains($key, '.') ? strstr($key, '.', true) : $key,
                array_map('strval', array_keys($shared))
            )));
        }

        $merged = $this->unpackDotProps(array_merge($shared, $props));

        return [$this->resolveProps($merged), $this->metadata()];
    }

    /**
     * Replace any prop provider with the props it contributes.
     *
     * A provider is passed without a key, so it arrives under a numeric one;
     * that is what distinguishes "this object is a set of props" from "this
     * object is the value of a prop".
     *
     * @param  array<array-key, mixed> $props
     * @return array<array-key, mixed>
     */
    private function expandProviders(array $props): array
    {
        $expanded = [];
        $context = null;

        foreach ($props as $key => $value) {
            if (is_int($key) && $value instanceof ProvidesInertiaProperties) {
                $context ??= new RenderContext($this->component, $this->request);

                foreach ($value->toInertiaProperties($context) as $providedKey => $providedValue) {
                    $expanded[$providedKey] = $providedValue;
                }

                continue;
            }

            $expanded[$key] = $value;
        }

        return $expanded;
    }

    /**
     * Turn 'auth.user' => $user into ['auth' => ['user' => $user]].
     *
     * Shared props are commonly declared in dotted form, and the client wants
     * a nested object; doing it here means a partial reload can name either
     * 'auth' or 'auth.user' and be understood.
     *
     * @param  array<array-key, mixed> $props
     * @return array<array-key, mixed>
     */
    private function unpackDotProps(array $props): array
    {
        $unpacked = [];

        foreach ($props as $key => $value) {
            if (! is_string($key) || ! str_contains($key, '.')) {
                $unpacked[$key] = $value;

                continue;
            }

            $segments = explode('.', $key);
            $target = &$unpacked;

            foreach ($segments as $index => $segment) {
                if ($index === count($segments) - 1) {
                    $target[$segment] = $value;

                    break;
                }

                if (! isset($target[$segment]) || ! is_array($target[$segment])) {
                    $target[$segment] = [];
                }

                $target = &$target[$segment];
            }

            unset($target);
        }

        return $unpacked;
    }

    /**
     * Walk the prop tree, keeping what this request should carry.
     *
     * @param  array<array-key, mixed> $props
     * @return array<string, mixed>
     */
    private function resolveProps(array $props, string $prefix = ''): array
    {
        $resolved = [];

        foreach ($props as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (! $this->wants($path, $value)) {
                continue;
            }

            if ($value instanceof ScrollProp) {
                // Which end is being extended is a property of this request,
                // so it is settled before the merge flags are read.
                $value->configureMergeIntent($this->request);
            }

            $this->recordMetadata($path, $value);

            if ($value instanceof Deferrable && $value->shouldDefer() && ! $this->isPartial) {
                // Advertised in the metadata, not sent. The client comes back
                // for it, and only then does the callback run.
                continue;
            }

            if ($value instanceof Onceable && $this->clientAlreadyHas($value, $path)) {
                continue;
            }

            $resolved[$key] = $this->resolveValue($value, $path, $props);
        }

        return $resolved;
    }

    /**
     * Is this prop part of this response?
     */
    private function wants(string $path, mixed $value): bool
    {
        if ($value instanceof AlwaysProp) {
            return true;
        }

        /*
         * A prop that opts out of the first load is sent only when asked for
         * by name — which, on a full load, never happens.
         */
        if (! $this->isPartial && $value instanceof IgnoreFirstLoad) {
            return $value instanceof Deferrable;
        }

        if ($this->only !== null && ! $this->matchesOnly($path) && ! $this->leadsToOnly($path)) {
            return false;
        }

        if ($this->except !== null && $this->matchesExcept($path)) {
            return false;
        }

        return true;
    }

    /** The path was named, or sits beneath something that was. */
    private function matchesOnly(string $path): bool
    {
        foreach ($this->only ?? [] as $only) {
            if ($path === $only || str_starts_with($path, $only . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The path is an ancestor of something named, so it has to be walked into
     * even though it was not itself requested.
     */
    private function leadsToOnly(string $path): bool
    {
        foreach ($this->only ?? [] as $only) {
            if (str_starts_with($only, $path . '.')) {
                return true;
            }
        }

        return false;
    }

    private function matchesExcept(string $path): bool
    {
        foreach ($this->except ?? [] as $except) {
            if ($path === $except || str_starts_with($path, $except . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Note what the client needs to know about how to treat this prop.
     */
    private function recordMetadata(string $path, mixed $value): void
    {
        if ($value instanceof Deferrable && $value->shouldDefer() && ! $this->isPartial) {
            $this->deferredProps[$value->group()][] = $path;
        }

        if ($value instanceof Onceable && $value->shouldResolveOnce()) {
            $this->onceProps[$path] = array_filter([
                'key'       => $value->getKey() ?? $path,
                'expiresAt' => $value->expiresAt(),
            ], static fn (mixed $entry): bool => $entry !== null);
        }

        if ($value instanceof ScrollProp) {
            $this->scrollProps[$path] = $value->metadata();
        }

        if (! $value instanceof Mergeable || ! $value->shouldMerge()) {
            return;
        }

        if ($value->shouldDeepMerge()) {
            $this->deepMergeProps[] = $path;
        } else {
            $this->mergeProps[] = $path;
        }

        /*
         * A nested path is reported as its full dotted path, because the
         * client applies the merge against the page object and has no idea
         * which prop a bare 'data' belonged to.
         */
        foreach ($value->appendsAtPaths() as $nested) {
            $this->mergeProps[] = $path . '.' . $nested;
        }

        foreach ($value->prependsAtPaths() as $nested) {
            $this->prependProps[] = $path . '.' . $nested;
        }

        if ($value->prependsAtRoot()) {
            $this->prependProps[] = $path;
        }

        if ($value->matchesOn() !== []) {
            $this->matchPropsOn[$path] = $value->matchesOn();
        }
    }

    /**
     * Does the client already hold this once-prop?
     *
     * A prop marked fresh is sent regardless — that is the case where the
     * value has changed underneath a client that thinks it is current.
     */
    private function clientAlreadyHas(Onceable $value, string $path): bool
    {
        if (! $value->shouldResolveOnce() || $value->shouldBeRefreshed()) {
            return false;
        }

        return in_array($value->getKey() ?? $path, $this->loadedOnceProps, true);
    }

    /**
     * Evaluate one prop, recursing into arrays so a nested closure is only
     * called when its own path survived the filter.
     */
    private function resolveValue(mixed $value, string $path, array $siblings = []): mixed
    {
        /*
         * A single-prop provider decides its own value from where it was
         * placed, so it is given the key, its siblings and the request rather
         * than being called bare.
         */
        if ($value instanceof ProvidesInertiaProperty) {
            $key = str_contains($path, '.') ? substr(strrchr($path, '.') ?: '', 1) : $path;

            $value = $value->toInertiaProperty(new PropertyContext($key, $siblings, $this->request));
        }

        if (is_object($value) && is_callable($value)) {
            /*
             * A rescued prop reports its failure instead of raising it. The
             * page is already on screen by the time a deferred prop resolves,
             * so an exception here would replace a working page over a value
             * it was built to arrive without.
             */
            if ($value instanceof Rescuable && $value->shouldRescue()) {
                try {
                    $value = $value();
                } catch (\Throwable) {
                    $this->rescuedProps[] = $path;

                    return null;
                }
            } else {
                $value = $value();
            }
        }

        if (is_array($value)) {
            return $this->resolveProps($value, $path);
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            $array = $value->toArray();

            return is_array($array) ? $this->resolveProps($array, $path) : $array;
        }

        return $value;
    }

    /**
     * The metadata keys, with the empty ones dropped.
     *
     * Omitted rather than sent empty: the client treats a missing key and an
     * empty one the same, and every page payload would otherwise carry four
     * empty arrays.
     *
     * @return array<string, mixed>
     */
    private function metadata(): array
    {
        return array_filter([
            'sharedProps'    => $this->sharedPropKeys,
            'mergeProps'     => $this->mergeProps,
            'prependProps'   => $this->prependProps,
            'deepMergeProps' => $this->deepMergeProps,
            'matchPropsOn'   => $this->matchPropsOn,
            'deferredProps'  => $this->deferredProps,
            'rescuedProps'   => $this->rescuedProps,
            'scrollProps'    => $this->scrollProps,
            'onceProps'      => $this->onceProps,
        ], static fn (array $value): bool => $value !== []);
    }

    /**
     * A comma-separated header as a list, or null when it is absent.
     *
     * @return array<int, string>|null
     */
    private function parseHeader(string $header): ?array
    {
        $value = $this->request->header($header);

        if ($value === null || $value === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $part): bool => $part !== ''));
    }
}
