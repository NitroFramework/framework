<?php

namespace Nitro\Htmx\Support;

use Nitro\Exceptions\HttpException;
use Nitro\Htmx\HtmxComponent;

class HxObfuscator
{
    /** Internal actions that aren't real class methods but still need hashing. */
    private const INTERNAL_ACTIONS = ['__lazy'];

    /** @var array<string,string>  hash → component name */
    private array $reverseComponents = [];

    /** @var array<string, array<string,string>>  component → (hash → action) */
    private array $reverseActions = [];

    /**
     * @param  string|null  $componentBaseDir   Filesystem path matching $componentNamespace.
     *                                          When set, the obfuscator auto-discovers all
     *                                          HtmxComponent subclasses in that directory.
     *                                          The $allowedComponents list is additive — use
     *                                          it to whitelist components that live elsewhere
     *                                          or to explicitly enumerate (defensive deployments).
     */
    public function __construct(
        private bool $enabled,
        private string $appKey,
        private string $componentNamespace,
        private array $allowedComponents = [],
        private ?string $componentBaseDir = null,
    ) {
        if ($this->enabled) {
            $this->precomputeComponentMap();
        }
    }

    public function obfuscate(string $name): string
    {
        if (!$this->enabled) {
            return $name;
        }
        return substr(hash_hmac('sha256', $name, $this->appKey), 0, 16);
    }

    public function obfuscateAction(string $action, string $component): string
    {
        if (!$this->enabled) {
            return $action;
        }
        return $this->obfuscate($action . $component);
    }

    public function reverseLookup(string $hash): string
    {
        if (!$this->enabled) {
            return $hash;
        }

        if (!isset($this->reverseComponents[$hash])) {
            throw new HttpException(404, "Component not found.");
        }
        return $this->reverseComponents[$hash];
    }

    public function reverseActionLookup(string $componentName, string $actionHash): string
    {
        if (!$this->enabled) {
            return $actionHash;
        }

        $map = $this->actionMapFor($componentName);

        if (!isset($map[$actionHash])) {
            throw new HttpException(404, "Action not found.");
        }
        return $map[$actionHash];
    }

    /**
     * Pre-compute hash → component name lookup so the request hot-path is O(1).
     * Sources: explicit allowlist + filesystem auto-discovery (when a base
     * directory was provided).
     */
    private function precomputeComponentMap(): void
    {
        $names = array_values(array_unique(array_merge(
            $this->allowedComponents,
            $this->discoverFromFilesystem(),
        )));

        foreach ($names as $name) {
            $this->reverseComponents[$this->obfuscate($name)] = $name;
        }
    }

    /**
     * Scan the component directory for files that match the PSR-4
     * convention against $componentNamespace, returning their lcfirst
     * short names. Skips files that don't actually contain a subclass
     * of HtmxComponent so a stray helper file in the same folder
     * doesn't pollute the allowlist.
     *
     * @return string[]
     */
    private function discoverFromFilesystem(): array
    {
        if ($this->componentBaseDir === null || !is_dir($this->componentBaseDir)) {
            return [];
        }

        $namespace = rtrim($this->componentNamespace, '\\');
        $names = [];

        foreach (glob($this->componentBaseDir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $short = basename($file, '.php');
            $fqcn  = $namespace . '\\' . $short;
            if (class_exists($fqcn) && is_subclass_of($fqcn, HtmxComponent::class)) {
                $names[] = lcfirst($short);
            }
        }

        return $names;
    }

    /**
     * Lazy build + memoize a per-component action hash map. Touched once
     * per component per request — subsequent calls are O(1).
     *
     * @return array<string,string>
     */
    private function actionMapFor(string $componentName): array
    {
        if (isset($this->reverseActions[$componentName])) {
            return $this->reverseActions[$componentName];
        }

        $map = [];
        foreach (self::INTERNAL_ACTIONS as $action) {
            $map[$this->obfuscateAction($action, $componentName)] = $action;
        }

        $className = rtrim($this->componentNamespace, '\\') . '\\'
            . str_replace(['-', '_'], '', ucwords($componentName, '-_'));

        if (!class_exists($className)) {
            throw new HttpException(404, "Component [{$className}] not found.");
        }

        foreach ((new \ReflectionClass($className))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->name;
            if (str_starts_with($name, '__')) continue;
            $map[$this->obfuscateAction($name, $componentName)] = $name;
        }

        return $this->reverseActions[$componentName] = $map;
    }
}
