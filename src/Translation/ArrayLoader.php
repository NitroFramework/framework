<?php

namespace Nitro\Translation;

use Nitro\Translation\Contracts\Loader;

/**
 * Holds lines in memory.
 *
 * For a test that wants to assert on a translated message without putting a
 * lang directory beside it, and for a package that generates its lines rather
 * than shipping them.
 */
class ArrayLoader implements Loader
{
    /** @var array<string, array<string, array<string, mixed>>> namespace.group.locale */
    protected array $messages = [];

    /** @return array<string, mixed> */
    public function load(string $locale, string $group, ?string $namespace = null): array
    {
        $namespace ??= '*';

        return $this->messages[$namespace][$group][$locale] ?? [];
    }

    /**
     * Put a group's lines in place.
     *
     * @param array<string, mixed> $messages
     */
    public function addMessages(string $locale, string $group, array $messages, ?string $namespace = null): static
    {
        $this->messages[$namespace ?? '*'][$group][$locale] = $messages;

        return $this;
    }

    /**
     * A namespace hint is a directory, and this loader reads no directories,
     * so the lines are added under the namespace directly instead.
     */
    public function addNamespace(string $namespace, string $hint): void
    {
        //
    }

    /** @return array<string, string> */
    public function namespaces(): array
    {
        return [];
    }

    public function addJsonPath(string $path): void
    {
        //
    }
}
