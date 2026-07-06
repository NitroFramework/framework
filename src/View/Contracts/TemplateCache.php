<?php

namespace Nitro\View\Contracts;

/**
 * Resolves, compiles, and caches compiled template files.
 */
interface TemplateCache
{
    public function resolve(string $templateFile, string $view): string;
    public function compile(string $templateFile, string $view): void;
    public function clear(): void;
    public function clearView(string $view): void;
    public function getCacheFilePath(string $view): string;
    public function getStats(): array;
    public function setCacheEnabled(bool $enabled): void;
    public function setCacheExpiry(int $seconds): void;
}