<?php

namespace Nitro\View\Contracts;

/**
 * Stores the compiled form of each template and decides when it is stale.
 *
 * Artefacts are addressed by the template's own path, never by the view name:
 * one name can resolve to different files.
 */
interface TemplateCache
{
    /**
     * Get the compiled path for a template, compiling it if stale.
     *
     * @param  string $templateFile Absolute path to the source template.
     * @param  string $view         The name it was requested under.
     * @return string
     */
    public function resolve(string $templateFile, string $view): string;

    /**
     * Compile a template without returning a path to include.
     *
     * @param string $templateFile Absolute path to the source template.
     * @param string $view         The name it is known by.
     */
    public function compile(string $templateFile, string $view): void;

    /**
     * Discard every compiled template.
     */
    public function clear(): void;

    /**
     * Discard the compiled form of a single template.
     *
     * @param string $templateFile Absolute path to the source template.
     */
    public function clearView(string $templateFile): void;

    /**
     * Get where the compiled form of a template is kept.
     *
     * @param string $templateFile Absolute path to the source template.
     */
    public function getCacheFilePath(string $templateFile): string;

    /**
     * Get counts and sizes describing the cache.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array;

    /**
     * Turn caching on or off.
     */
    public function setCacheEnabled(bool $enabled): void;

    /**
     * Set how long a compiled template stays valid. Zero disables the check.
     */
    public function setCacheExpiry(int $seconds): void;
}
