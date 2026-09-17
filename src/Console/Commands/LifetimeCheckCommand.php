<?php

namespace Nitro\Console\Commands;

use Closure;
use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Container\Container;
use Nitro\Container\Lifetime;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;
use Throwable;

/**
 * `nitro lifetimes:check` — report services that hold something shorter-lived
 * than themselves.
 *
 * The container refuses such a dependency when it builds one, which only covers
 * what a given run happens to resolve. This walks every registered binding
 * instead, resolving nothing, so a service that no request and no test has ever
 * asked for is checked too. Suitable for CI: it exits non-zero on a finding.
 *
 * Two passes, reported separately because their evidence differs:
 *
 *  - Constructors, read by reflection. A class-typed parameter naming something
 *    shorter-lived than its consumer is a fact, not a guess.
 *  - Factory bodies, read as text. A closure's dependencies are whatever it
 *    calls, which cannot be had by reflection, so the source between its first
 *    and last line is scanned for container calls. Heuristic: it can miss a
 *    name built at runtime, and it reads a call in a comment as real.
 */
class LifetimeCheckCommand implements CommandInterface
{
    /** Container methods whose first argument names a service being resolved. */
    private const RESOLVING_CALLS = ['get', 'make', 'createOrResolve', 'resolve'];

    public function __construct(
        private Container $container,
        private OutputFormatter $output,
    ) {}

    public function getCommands(): array
    {
        return [
            'lifetimes:check' => 'Report bindings that depend on something shorter-lived than themselves',
        ];
    }

    public function handle(string $signature, array $arguments): void
    {
        $findings = array_merge($this->checkConstructors(), $this->checkFactories());

        if ($findings === []) {
            $this->output->success('No binding depends on anything shorter-lived than itself.');

            return;
        }

        $this->report($findings);

        exit(1);
    }

    /**
     * Class-string bindings, checked against their constructors.
     *
     * @return array<int, array{consumer: string, consumerLifetime: Lifetime, dependency: string, dependencyLifetime: Lifetime, evidence: string}>
     */
    private function checkConstructors(): array
    {
        $findings = [];

        foreach ($this->container->registeredBindings() as $name => $value) {
            $class = $this->concreteClassOf($name, $value);

            if ($class === null) {
                continue;
            }

            $consumerLifetime = $this->container->lifetimeOf($name);

            foreach ($this->constructorTypes($class) as $dependency) {
                $dependencyLifetime = $this->container->lifetimeOf($dependency);

                if (! $this->captures($consumerLifetime, $dependencyLifetime)) {
                    continue;
                }

                $findings[] = [
                    'consumer' => $name,
                    'consumerLifetime' => $consumerLifetime,
                    'dependency' => $dependency,
                    'dependencyLifetime' => $dependencyLifetime,
                    'evidence' => 'constructor parameter',
                ];
            }
        }

        return $findings;
    }

    /**
     * Closure bindings, checked against the container calls in their source.
     *
     * @return array<int, array{consumer: string, consumerLifetime: Lifetime, dependency: string, dependencyLifetime: Lifetime, evidence: string}>
     */
    private function checkFactories(): array
    {
        $findings = [];

        foreach ($this->container->registeredBindings() as $name => $value) {
            if (! $value instanceof Closure) {
                continue;
            }

            $consumerLifetime = $this->container->lifetimeOf($name);

            // Only a process-lived factory can capture anything: a request-lived
            // one is discarded at the same moment whatever it resolved is.
            if ($consumerLifetime !== Lifetime::Process) {
                continue;
            }

            foreach ($this->servicesNamedIn($value) as $dependency) {
                $dependencyLifetime = $this->container->lifetimeOf($dependency);

                if (! $this->captures($consumerLifetime, $dependencyLifetime)) {
                    continue;
                }

                $findings[] = [
                    'consumer' => $name,
                    'consumerLifetime' => $consumerLifetime,
                    'dependency' => $dependency,
                    'dependencyLifetime' => $dependencyLifetime,
                    'evidence' => 'resolved inside the factory',
                ];
            }
        }

        return $findings;
    }

    /**
     * Whether holding the dependency means holding it past its own lifetime.
     *
     * A transient is exempt: it has no lifetime of its own and takes the
     * consumer's, so nothing that anything else will look for again is being
     * kept. Only a scope something resets — a request — can be outlived.
     */
    private function captures(Lifetime $consumer, Lifetime $dependency): bool
    {
        return $dependency !== Lifetime::Transient && $consumer->outlives($dependency);
    }

    /**
     * The class a binding builds, or null when there is nothing to reflect on.
     *
     * A binding may hold a class name, a closure, or an already-built instance.
     * Only the first two describe a constructor the container will call; an
     * instance was built by whoever registered it.
     */
    private function concreteClassOf(string $name, mixed $value): ?string
    {
        if (is_string($value) && class_exists($value)) {
            return $value;
        }

        // singleton(Foo::class) records Foo as both the name and the value, but
        // singleton('router') records the name alone.
        if ($value === $name && class_exists($name)) {
            return $name;
        }

        return null;
    }

    /**
     * The class-typed constructor parameters of a class.
     *
     * @return array<int, string>
     */
    private function constructorTypes(string $class): array
    {
        try {
            $constructor = (new ReflectionClass($class))->getConstructor();
        } catch (Throwable) {
            return [];
        }

        if ($constructor === null) {
            return [];
        }

        $types = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                $types[] = $type->getName();
            }
        }

        return $types;
    }

    /**
     * Service names a closure resolves from the container, read from its source.
     *
     * Matches `$anything->get('name')` and `->make(Some::class)` across the
     * closure's own lines. Reflection can say where a closure is written but
     * not what it does, so this is the only way to see inside one short of
     * running it — which is what the command exists to avoid.
     *
     * @return array<int, string>
     */
    private function servicesNamedIn(Closure $closure): array
    {
        try {
            $reflection = new ReflectionFunction($closure);
            $file = $reflection->getFileName();
            $start = $reflection->getStartLine();
            $end = $reflection->getEndLine();
        } catch (Throwable) {
            return [];
        }

        if ($file === false || $start === false || $end === false || ! is_readable($file)) {
            return [];
        }

        $lines = array_slice(file($file) ?: [], $start - 1, $end - $start + 1);
        $source = implode('', $lines);

        $calls = implode('|', self::RESOLVING_CALLS);
        $pattern = '/->(?:' . $calls . ')\(\s*(?:\'([^\']+)\'|"([^"]+)"|([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::class)/';

        preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);

        $names = [];

        foreach ($matches as $match) {
            $name = $match[1] ?: ($match[2] ?: ($match[3] ?? ''));

            if ($name !== '' && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @param array<int, array<string, mixed>> $findings */
    private function report(array $findings): void
    {
        $count = count($findings);
        $noun = $count === 1 ? 'binding holds' : 'bindings hold';

        $this->output->error("{$count} {$noun} something shorter-lived than itself.");
        $this->output->writeln('');

        foreach ($findings as $finding) {
            $consumer = $finding['consumer'];
            $dependency = $finding['dependency'];

            $this->output->writeln(
                '  ' . $this->output->color($consumer, 'yellow')
                . " ({$finding['consumerLifetime']->name})"
                . ' → ' . $this->output->color($dependency, 'red')
                . " ({$finding['dependencyLifetime']->name})"
            );
            $this->output->writeln("      via {$finding['evidence']}");
        }

        $this->output->writeln('');
        $this->output->info(
            'Each one keeps the first request\'s instance for the life of the process. '
            . 'Bind the consumer with scoped(), or have it resolve the dependency per call.'
        );
    }
}
