<?php

namespace Nitro\Container;

use Closure;

/**
 * Fluent builder for a contextual binding.
 *
 *   $container->when(ReportController::class)
 *             ->needs(Filesystem::class)
 *             ->give(fn () => Storage::disk('reports'));
 *
 * Returned by {@see Container::when()}; nothing is recorded until give() is
 * called, so an unfinished chain binds nothing.
 */
class ContextualBindingBuilder
{
    /** The dependency being described, set by needs(). */
    private ?string $needs = null;

    /** @param array<int, string> $concrete The consumers this applies to. */
    public function __construct(
        private Container $container,
        private array $concrete,
    ) {}

    /**
     * The dependency the consumers ask for: a class or interface name, or a
     * constructor parameter name such as '$timeout'.
     */
    public function needs(string $abstract): static
    {
        $this->needs = $abstract;

        return $this;
    }

    /**
     * What to hand over instead: a concrete class name, a closure, or a value.
     */
    public function give(mixed $implementation): void
    {
        if ($this->needs === null) {
            return;
        }

        $this->container->addContextualBinding($this->concrete, $this->needs, $implementation);
    }

    /** Hand over every service registered under a tag. */
    public function giveTagged(string $tag): void
    {
        $this->give(fn (Container $container) => $container->tagged($tag));
    }

    /**
     * Hand over a configuration value, falling back to $default when the key
     * is not set.
     */
    public function giveConfig(string $key, mixed $default = null): void
    {
        $this->give(static fn () => config($key, $default));
    }

    /** Build the value once and hand the same instance to every consumer. */
    public function giveOnce(Closure $factory): void
    {
        $resolved = false;
        $value = null;

        $this->give(function (Container $container) use ($factory, &$resolved, &$value) {
            if (! $resolved) {
                $value = $factory($container);
                $resolved = true;
            }

            return $value;
        });
    }
}
