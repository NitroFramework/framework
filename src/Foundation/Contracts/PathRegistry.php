<?php

namespace Nitro\Foundation\Contracts;

/**
 * Where the application keeps things.
 *
 * Every method takes an optional path to append and returns an absolute one, so
 * `storage('logs/nitro.log')` reads as the file rather than as string joining at
 * the call site. The container binds the 'paths' alias, the concrete
 * {@see \Nitro\Foundation\PathRegistry} and this interface to one instance;
 * classes inject this contract and templates read via the path helpers.
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
}
