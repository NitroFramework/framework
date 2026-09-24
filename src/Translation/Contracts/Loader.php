<?php

namespace Nitro\Translation\Contracts;

/**
 * Where a locale's lines come from.
 *
 * The translator does the choosing — locale, fallback, plural form,
 * placeholders — and nothing else. Finding the lines is this, so a test can
 * hand over an array, a package can hand over its own directory, and neither
 * has to put a file on disk to be translated.
 *
 * Only what every source can answer is here. Registering a directory to
 * search is on {@see \Nitro\Translation\FileLoader}, because there is no
 * honest answer to it from a loader that holds its lines in memory.
 */
interface Loader
{
    /**
     * The lines for one group of one locale.
     *
     * A group of '*' in a namespace of '*' means the locale's JSON file: one
     * flat file per locale, keyed by the source text itself.
     *
     * @return array<string, mixed>
     */
    public function load(string $locale, string $group, ?string $namespace = null): array;

    /** Register a package's translation directory under a namespace. */
    public function addNamespace(string $namespace, string $hint): void;

    /** @return array<string, string> Namespace => directory. */
    public function namespaces(): array;

    /** Add a directory to search for JSON files. */
    public function addJsonPath(string $path): void;
}
