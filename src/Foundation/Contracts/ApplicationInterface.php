<?php

namespace Nitro\Foundation\Contracts;

use Nitro\Container\Contracts\ContainerInterface as Container;
use Nitro\Foundation\MaintenanceMode;
use Nitro\Foundation\Providers\ServiceProvider;

/**
 * What a provider, middleware or command may ask of the application.
 */
interface ApplicationInterface
{
    /** The container this application is assembled in. */
    public function getContainer(): Container;

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

    /** Whether the application runs in the local environment. */
    public function isLocal(): bool;

    /** Whether the application runs in production. */
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

    /** Whether the bootstrappers have run. */
    public function hasBeenBootstrapped(): bool;

    /** The locale in use. */
    public function getLocale(): string;

    /** Set the locale for the rest of this request. */
    public function setLocale(string $locale): void;

    /** Whether the given locale is the one in use. */
    public function isLocale(string $locale): bool;

    /** The locale a missing translation falls back to. */
    public function getFallbackLocale(): string;

    /** What decides whether the application is down. */
    public function maintenanceMode(): MaintenanceMode;

    /**
     * The registered instance of a provider, or null if it has not registered.
     *
     * @param class-string|ServiceProvider $provider
     */
    public function getProvider(string|ServiceProvider $provider): ?ServiceProvider;

    /**
     * Whether a provider has registered.
     *
     * @param class-string|ServiceProvider $provider
     */
    public function providerIsLoaded(string|ServiceProvider $provider): bool;

    /** Whether the configuration has been compiled. */
    public function configurationIsCached(): bool;

    /** Whether the route table has been compiled. */
    public function routesAreCached(): bool;
}
