<?php

namespace Nitro\Foundation\Contracts;

/**
 * Resolve the application's directories to absolute paths.
 *
 * Each directory method appends the optional path it is given.
 */
interface PathRegistry
{
    /** The application root. */
    public function base(string $path = ''): string;

    /** Where configuration files live. */
    public function config(string $path = ''): string;

    /** Writable storage: logs, compiled views, uploads. */
    public function storage(string $path = ''): string;

    /** Writable cache, below storage. */
    public function cache(string $path = ''): string;

    /** The compiled configuration file `optimize` writes. */
    public function cachedConfig(): string;

    /** The compiled route file `optimize` writes. */
    public function cachedRoutes(): string;

    /** The cached provider manifest. */
    public function cachedProviders(): string;

    /** The cached package manifest from package discovery. */
    public function cachedPackages(): string;

    /** The compiled container factories `optimize` writes. */
    public function cachedContainer(): string;

    /** The opcache warmup bundle for compiled views. */
    public function cachedViewWarmup(): string;

    /** The cached database schema. */
    public function cachedSchema(): string;

    /** The generated opcache preload script. */
    public function cachedPreload(): string;

    /** Where database files live. */
    public function database(string $path = ''): string;

    /** Where migrations live. */
    public function migrations(string $path = ''): string;

    /** Where seeders live. */
    public function seeders(string $path = ''): string;

    /** Where model factories live. */
    public function factories(string $path = ''): string;

    /** Where uncompiled assets and templates live. */
    public function resources(string $path = ''): string;

    /** Where view templates live, below resources. */
    public function views(string $path = ''): string;

    /** The web-server document root. */
    public function public(string $path = ''): string;

    /** Where the application's own classes live. */
    public function app(string $path = ''): string;

    /** Where translation files live. */
    public function lang(string $path = ''): string;

    /** Where the framework is bootstrapped from. */
    public function bootstrap(string $path = ''): string;

    /** The compiled event listener map. */
    public function cachedEvents(): string;

    /** Join path segments with this platform's separator, dropping empty ones. */
    public function join(string $base, string ...$segments): string;

    /**
     * Point a directory somewhere other than its default.
     *
     * A relative path is taken from the base path, an absolute one as given.
     * Set it before anything reads that path — a bootstrapper, not a request.
     */
    public function use(string $name, string $path): static;

    /** Move the application root, and everything deriving from it with it. */
    public function useBase(string $path): static;
}
