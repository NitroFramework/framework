<?php

namespace Nitro\Translation;

/**
 * Looks up translated strings.
 *
 *     Lang::get('messages.welcome', ['name' => 'Ada']);
 *     Lang::choice('messages.items', 3, ['count' => 3]);
 *
 * Lines live in lang/{locale}/{group}.php as nested arrays, addressed with
 * dots: 'messages.errors.required' is ['errors']['required'] in
 * lang/en/messages.php. A JSON file at lang/{locale}.json holds lines keyed
 * by the English text itself, for translating whole sentences.
 *
 * A key with no translation is returned unchanged, so a missing line shows
 * up as itself rather than as an empty string.
 */
class Translator
{
    /** @var array<string, array<string, mixed>> Loaded groups, keyed locale.group. */
    protected array $loaded = [];

    /** @var array<string, array<string, string>> Loaded JSON lines, keyed by locale. */
    protected array $json = [];

    public function __construct(
        protected string $path,
        protected string $locale = 'en',
        protected string $fallback = 'en',
    ) {}

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getFallback(): string
    {
        return $this->fallback;
    }

    public function setFallback(string $locale): static
    {
        $this->fallback = $locale;

        return $this;
    }

    /** Whether this locale is the current one. */
    public function isLocale(string $locale): bool
    {
        return $this->locale === $locale;
    }

    /** Whether a line exists for the key. */
    public function has(string $key, ?string $locale = null): bool
    {
        return $this->get($key, [], $locale) !== $key;
    }

    /**
     * The translated line, or the key when there is none.
     *
     * @param array<string, mixed> $replace
     */
    public function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale ??= $this->locale;

        $line = $this->lineFor($key, $locale) ?? $this->lineFor($key, $this->fallback);

        return $this->replace($line ?? $key, $replace);
    }

    /**
     * The line matching a count.
     *
     * Plural forms are separated by a pipe, and a form may carry an explicit
     * range: '{0} none|[1,19] some|[20,*] many'.
     *
     * @param array<string, mixed> $replace
     */
    public function choice(string $key, int $number, array $replace = [], ?string $locale = null): string
    {
        $line = $this->get($key, $replace, $locale);

        $segments = explode('|', $line);

        foreach ($segments as $segment) {
            if (($explicit = $this->matchExplicit($segment, $number)) !== null) {
                return $this->replace($explicit, $replace + ['count' => $number]);
            }
        }

        $plain = array_values(array_filter(
            $segments,
            static fn (string $segment): bool => ! preg_match('/^[\{\[]/', trim($segment))
        ));

        $chosen = $number === 1 ? ($plain[0] ?? $line) : ($plain[1] ?? $plain[0] ?? $line);

        return $this->replace($chosen, $replace + ['count' => $number]);
    }

    /** Add lines at runtime, without a file. */
    public function addLines(array $lines, string $locale, string $group): static
    {
        $this->loaded[$locale . '.' . $group] = array_merge(
            $this->loaded[$locale . '.' . $group] ?? [],
            $lines
        );

        return $this;
    }

    /**
     * Find a line, from a group file or the locale's JSON file.
     */
    protected function lineFor(string $key, string $locale): ?string
    {
        if (str_contains($key, '.')) {
            [$group, $item] = explode('.', $key, 2);

            $lines = $this->loadGroup($locale, $group);
            $value = $this->dig($lines, $item);

            if (is_string($value)) {
                return $value;
            }
        }

        $json = $this->loadJson($locale);

        return isset($json[$key]) && is_string($json[$key]) ? $json[$key] : null;
    }

    /** @return array<string, mixed> */
    protected function loadGroup(string $locale, string $group): array
    {
        $cacheKey = $locale . '.' . $group;

        if (isset($this->loaded[$cacheKey])) {
            return $this->loaded[$cacheKey];
        }

        $file = $this->path . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . $group . '.php';

        $lines = is_file($file) ? require $file : [];

        return $this->loaded[$cacheKey] = is_array($lines) ? $lines : [];
    }

    /** @return array<string, string> */
    protected function loadJson(string $locale): array
    {
        if (isset($this->json[$locale])) {
            return $this->json[$locale];
        }

        $file = $this->path . DIRECTORY_SEPARATOR . $locale . '.json';

        $lines = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

        return $this->json[$locale] = is_array($lines) ? $lines : [];
    }

    /** Read a dotted path out of a nested array. */
    protected function dig(array $lines, string $key): mixed
    {
        foreach (explode('.', $key) as $segment) {
            if (! is_array($lines) || ! array_key_exists($segment, $lines)) {
                return null;
            }

            $lines = $lines[$segment];
        }

        return $lines;
    }

    /**
     * Substitute :placeholders, matching the case of the placeholder.
     *
     * @param array<string, mixed> $replace
     */
    protected function replace(string $line, array $replace): string
    {
        foreach ($replace as $key => $value) {
            $value = (string) $value;

            $line = str_replace(
                [':' . $key, ':' . strtoupper($key), ':' . ucfirst($key)],
                [$value, strtoupper($value), ucfirst($value)],
                $line
            );
        }

        return $line;
    }

    /** A segment carrying an explicit count or range, when it matches. */
    private function matchExplicit(string $segment, int $number): ?string
    {
        $segment = trim($segment);

        if (preg_match('/^\{(\d+)\}(.*)$/s', $segment, $matches) === 1) {
            return (int) $matches[1] === $number ? trim($matches[2]) : null;
        }

        if (preg_match('/^\[(\d+),\s*(\d+|\*)\](.*)$/s', $segment, $matches) === 1) {
            $from = (int) $matches[1];
            $to = $matches[2] === '*' ? PHP_INT_MAX : (int) $matches[2];

            return $number >= $from && $number <= $to ? trim($matches[3]) : null;
        }

        return null;
    }
}
