<?php

namespace Nitro\Foundation\Contracts;

use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Foundation\PathRegistry;
use Nitro\Foundation\Providers\ServiceProvider;

/**
 * What the rest of the framework may ask of the application.
 *
 * The Application was the one service with no contract — ConfigRepository and
 * ContainerInterface both have one, so every layer that needed the application
 * took the concrete class and inherited the whole composition root with it.
 *
 * Deliberately narrower than the class: registering providers, running
 * bootstrappers and wiring the container are the composition root's own work,
 * not something a consumer should reach for. What is here is what a provider,
 * a middleware or a command legitimately asks — where am I running, where are
 * my paths, and what happens after the response.
 */
interface ApplicationInterface
{
    /** The container this application is assembled in. */
    public function getContainer(): ContainerInterface;

    /** Every application path, derived from the base path. */
    public function paths(): PathRegistry;

    /** The framework version. */
    public function version(): string;

    /**
     * The current environment, or whether it is one of the given ones.
     *
     * Patterns may use * ('stag*' matches 'staging').
     */
    public function environment(string ...$environments): string|bool;

    public function isLocal(): bool;

    public function isProduction(): bool;

    /** Whether debug mode is on. Defaults to true before config has loaded. */
    public function isDebug(): bool;

    /** Whether a CLI process is driving the application. */
    public function runningInConsole(): bool;

    /** Whether a test runner is driving the application. */
    public function runningUnitTests(): bool;

    /** Whether {@see bootstrap()} has run. */
    public function isBootstrapped(): bool;

    /** Whether the application is down for maintenance. */
    public function isDownForMaintenance(): bool;

    /** Register a service provider, by class name or as a built instance. */
    public function register(string|ServiceProvider $provider): ServiceProvider;

    /** Register a callback to run after the response has been sent. */
    public function terminating(callable $callback): static;

    /** Run the terminating callbacks. */
    public function terminate(): void;
}
