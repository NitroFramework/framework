<?php

namespace Nitro\Database\Query;

use Closure;
use Nitro\Database\Query\Exceptions\QueryNotFoundException;

/**
 * Named queries — a reusable, app-wide query library, invoked by name.
 *
 * Where a model scope is a query FRAGMENT bound to one model (Student::active()),
 * a named query is a complete, reusable query registered under a name and invoked
 * like a service: query('students.honor_roll'). It composes with everything —
 * scopes, ->cache(), ->paginate(), and further constraints — because resolve()
 * returns a live builder.
 *
 * Define them in a manifest (a file under app/Queries/ that returns an array),
 * or fluently via query()->register(). Definitions are Closures, so a named query
 * may reference another (query('students')->...) and use model scopes freely.
 */
class QueryRegistry
{
    /** @var array<string, Closure> */
    protected array $queries = [];

    /** Register a single named query. */
    public function register(string $name, Closure $resolver): static
    {
        $this->queries[$name] = $resolver;

        return $this;
    }

    /**
     * Register a whole manifest at once: ['name' => fn () => <builder>, ...].
     *
     * @param array<string, Closure> $manifest
     */
    public function registerMany(array $manifest): static
    {
        foreach ($manifest as $name => $resolver) {
            $this->register($name, $resolver);
        }

        return $this;
    }

    /**
     * Register every public method of a Queries group as "{prefix}.{method}".
     * The class-based counterpart to registerMany() (see Queries).
     */
    public function registerGroup(Queries $group): static
    {
        $prefix = $group->prefix();

        foreach ($group->methods() as $method) {
            $name = $prefix !== '' ? "{$prefix}.{$method}" : $method;
            $this->register($name, fn (...$args) => $group->{$method}(...$args));
        }

        return $this;
    }

    /** Resolve a named query to a live builder, passing any parameters to its closure. */
    public function resolve(string $name, array $params = []): mixed
    {
        if (! isset($this->queries[$name])) {
            throw new QueryNotFoundException("Named query [{$name}] is not registered.");
        }

        return ($this->queries[$name])(...$params);
    }

    public function has(string $name): bool
    {
        return isset($this->queries[$name]);
    }

    /** All registered names. @return list<string> */
    public function names(): array
    {
        return array_keys($this->queries);
    }

    /**
     * Auto-load named-query definitions from every *.php in $dir. A file may:
     *   - `return ['name' => fn () => ...]`  — a closure manifest, or
     *   - define a Queries subclass (methods)  — auto-registered by method, or
     *   - call query()->register(...)          — as a side effect.
     * A missing directory is a no-op — apps needn't have one.
     *
     * @param string $namespace Namespace of class-based query groups (PSR-4 by filename).
     */
    public function loadFrom(string $dir, string $namespace = 'App\\Queries'): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*.php') ?: [] as $file) {
            // Class form first: a Queries subclass named after the file (PSR-4).
            // Loaded via the autoloader (idempotent) — never require'd, so booting
            // more than once (e.g. across tests) can't redeclare the class.
            $class = rtrim($namespace, '\\') . '\\' . pathinfo($file, PATHINFO_FILENAME);
            if (class_exists($class) && is_subclass_of($class, Queries::class)) {
                $this->registerGroup(new $class());
                continue;
            }

            // Manifest form: a file that returns [name => closure]. require re-runs
            // per boot, so it re-registers into each fresh registry.
            $manifest = require $file;
            if (is_array($manifest)) {
                $this->registerMany($manifest);
            }
        }
    }
}
