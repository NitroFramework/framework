<?php

namespace Tests\Wiring;


/**
 * Asking the container for a service gives you a usable one.
 *
 * Not "the class works" — every other suite covers that. This covers the step
 * between: that a provider's binding actually produces the thing it promises.
 */
class ContainerHandsOutWorkingServicesTest extends WiringTestCase
{
    /** Every name the container knows resolves, or is a documented exception. */
    public function test_every_registered_binding_resolves(): void
    {
        $failures = [];

        foreach ($this->everyServiceName() as $name) {
            if ($this->isSkipped($name)) {
                continue;
            }

            try {
                $service = $this->container->resolve($name);
            } catch (\Throwable $exception) {
                $failures[] = "{$name}: " . $exception->getMessage();
                continue;
            }

            if (! is_object($service) && ! is_array($service) && ! is_scalar($service)) {
                $failures[] = "{$name}: resolved to " . get_debug_type($service);
            }
        }

        $this->assertSame([], $failures, "these bindings do not resolve:\n" . implode("\n", $failures));
    }

    /**
     * A service named by a class or interface is an instance of it.
     *
     * Catches a binding wired to the wrong concrete, and a contract whose
     * implementation drifted out from under it.
     */
    public function test_a_binding_named_by_a_type_produces_that_type(): void
    {
        $failures = [];

        foreach ($this->everyServiceName() as $name) {
            if ($this->isSkipped($name) || ! (class_exists($name) || interface_exists($name))) {
                continue;
            }

            try {
                $service = $this->container->resolve($name);
            } catch (\Throwable) {
                continue; // the resolve test above owns this failure
            }

            if (! $service instanceof $name) {
                $failures[] = "{$name}: got " . get_debug_type($service);
            }
        }

        $this->assertSame([], $failures, "these bindings produce the wrong type:\n" . implode("\n", $failures));
    }

    /**
     * A binding's lifetime is what it declared.
     *
     * Pipeline was registered with bind(), whose third argument defaults to a
     * shared binding, while the comment above it explained that a pipeline
     * holds the value travelling through it and must never be shared. Nothing
     * noticed, because nothing resolved it from the container twice.
     */
    public function test_a_lifetime_is_what_the_binding_declared(): void
    {
        $failures = [];

        foreach ($this->everyServiceName() as $name) {
            if ($this->isSkipped($name)) {
                continue;
            }

            try {
                $first  = $this->container->resolve($name);
                $second = $this->container->resolve($name);
            } catch (\Throwable) {
                continue;
            }

            if (! is_object($first)) {
                continue;
            }

            // isShared() reads the binding under the name it is given and does
            // not follow an alias, so an alias to a singleton answers false.
            // The target is what declared the lifetime, so ask about that.
            $declaredShared = $this->container->isShared($this->container->getAlias($name));
            $shared         = $first === $second;

            if (! $declaredShared && $shared) {
                $failures[] = "{$name}: bound as transient but handed out the same instance twice";
            }

            if ($declaredShared && ! $shared) {
                $failures[] = "{$name}: bound as shared but handed out two instances";
            }
        }

        $this->assertSame([], $failures, "these bindings are not honoured:\n" . implode("\n", $failures));
    }

    /** An alias and the name it points at are one service, not two. */
    public function test_an_alias_resolves_to_its_target(): void
    {
        $failures = [];

        foreach ($this->container->registeredAliases() as $alias => $target) {
            if ($this->isSkipped($alias) || $this->isSkipped($target)) {
                continue;
            }

            try {
                $viaAlias  = $this->container->resolve($alias);
                $viaTarget = $this->container->resolve($target);
            } catch (\Throwable $exception) {
                $failures[] = "{$alias} -> {$target}: " . $exception->getMessage();
                continue;
            }

            if (! is_object($viaAlias)) {
                continue;
            }

            // A shared target must be the same object through either name; a
            // transient one need only be the same kind.
            $sameKind = $viaAlias::class === $viaTarget::class;

            if (! $this->container->isShared($target)) {
                $sameKind or $failures[] = "{$alias} -> {$target}: different types";
                continue;
            }

            if ($viaAlias !== $viaTarget) {
                $failures[] = "{$alias} -> {$target}: different instances";
            }
        }

        $this->assertSame([], $failures, "these aliases disagree with their targets:\n" . implode("\n", $failures));
    }

    /** A deferred provider's promise is kept: everything it provides resolves. */
    public function test_a_deferred_provider_provides_what_it_claims(): void
    {
        $deferred = $this->app->getDeferredServices();

        $this->assertNotSame([], $deferred, 'expected the framework to defer something');

        $failures = [];

        foreach ($deferred as $service => $provider) {
            if ($this->isSkipped($service)) {
                continue;
            }

            try {
                $this->container->resolve($service);
            } catch (\Throwable $exception) {
                $failures[] = "{$provider} promises {$service}: " . $exception->getMessage();
            }
        }

        $this->assertSame([], $failures, "these promises are not kept:\n" . implode("\n", $failures));
    }
}
