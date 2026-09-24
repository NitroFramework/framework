<?php

namespace Nitro\Translation;

use Closure;
use InvalidArgumentException;
use Nitro\Translation\Contracts\Loader;

/**
 * Looks up translated strings.
 *
 *     Lang::get('messages.welcome', ['name' => 'Ada']);
 *     Lang::choice('messages.items', 3);
 *
 * Lines live in lang/{locale}/{group}.php as nested arrays, addressed with
 * dots: 'messages.errors.required' is ['errors']['required'] in
 * lang/en/messages.php. A JSON file at lang/{locale}.json holds lines keyed
 * by the English text itself, for translating whole sentences, and is tried
 * first so __('Welcome back') works without inventing a key for it.
 *
 * A package registers its own directory and its lines are addressed with a
 * namespace: 'billing::invoice.paid'.
 *
 * A key with no translation is returned unchanged, so a missing line shows
 * up as itself rather than as an empty string.
 */
class Translator
{
    /**
     * Lines already read, as namespace => group => locale => lines.
     *
     * @var array<string, array<string, array<string, array<string, mixed>>>>
     */
    protected array $loaded = [];

    protected Loader $loader;

    protected string $locale;

    protected string $fallback;

    /** Chooses the plural form, built on first use. */
    protected ?MessageSelector $selector = null;

    /** What to do with a key that has no line anywhere. */
    protected ?Closure $missingKeyHandler = null;

    /**
     * Guards against a missing-key handler that translates, and so re-enters.
     */
    protected bool $handleMissingKeys = true;

    /** Decides which locales to try, and in what order. */
    protected ?Closure $localeResolver = null;

    /** @var array<class-string, callable> How to render an object passed as a replacement. */
    protected array $stringableHandlers = [];

    /**
     * Keys already split, so a key looked up on every request is split once.
     *
     * @var array<string, array{0: string, 1: string, 2: ?string}>
     */
    protected array $parsed = [];

    /**
     * @param Loader|string $loader A loader, or a lang directory to read from.
     */
    public function __construct(Loader|string $loader, string $locale = 'en', string $fallback = 'en')
    {
        $this->loader = is_string($loader) ? new FileLoader($loader) : $loader;
        $this->locale = $locale;
        $this->fallback = $fallback;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    /** The locale in use. Reads better than getLocale() at a call site. */
    public function locale(): string
    {
        return $this->getLocale();
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

    /** Whether a line exists, in this locale or the fallback. */
    public function has(string $key, ?string $locale = null, bool $fallback = true): bool
    {
        $locale ??= $this->locale;

        // A handler that reports or records a missing key should not fire for
        // a question about whether the key is missing.
        $handling = $this->handleMissingKeys;

        $this->handleMissingKeys = false;

        $line = $this->get($key, [], $locale, $fallback);

        $this->handleMissingKeys = $handling;

        // A JSON line may legitimately be the key itself — 'Welcome' translated
        // to 'Welcome' — so the loaded array is the authority where it has one.
        if (($this->loaded['*']['*'][$locale][$key] ?? null) !== null) {
            return true;
        }

        return $line !== $key;
    }

    /** Whether a line exists in this exact locale, ignoring the fallback. */
    public function hasForLocale(string $key, ?string $locale = null): bool
    {
        return $this->has($key, $locale, false);
    }

    /**
     * The translated line, or the key when there is none.
     *
     * Returns an array when the key addresses a group of lines rather than
     * one, which is how validation reads its per-type messages.
     *
     * @param array<string, mixed> $replace
     * @return string|array<string, mixed>
     */
    public function get(string $key, array $replace = [], ?string $locale = null, bool $fallback = true): string|array
    {
        $locale ??= $this->locale;

        // One flat file per locale, so this is a lookup rather than a search.
        $this->load('*', '*', $locale);

        $line = $this->loaded['*']['*'][$locale][$key] ?? null;

        if ($line === null) {
            [$namespace, $group, $item] = $this->parseKey($key);

            foreach ($fallback ? $this->localesToTry($locale) : [$locale] as $candidate) {
                $found = $this->lineFrom($namespace, $group, $candidate, $item, $replace);

                if ($found !== null) {
                    return $found;
                }
            }

            $key = $this->handleMissingKey($key, $replace, $locale, $fallback);
        }

        return $this->makeReplacements($line ?: $key, $replace);
    }

    /**
     * The line, insisting it is a string.
     *
     * @param array<string, mixed> $replace
     *
     * @throws InvalidArgumentException when the key addresses a group.
     */
    public function string(string $key, array $replace = [], ?string $locale = null, bool $fallback = true): string
    {
        $line = $this->get($key, $replace, $locale, $fallback);

        if (! is_string($line)) {
            throw new InvalidArgumentException("Translation for [{$key}] is not a string.");
        }

        return $line;
    }

    /**
     * The lines, insisting the key addresses a group.
     *
     * @param array<string, mixed> $replace
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when the key addresses one line.
     */
    public function array(string $key, array $replace = [], ?string $locale = null, bool $fallback = true): array
    {
        $line = $this->get($key, $replace, $locale, $fallback);

        if (! is_array($line)) {
            throw new InvalidArgumentException("Translation for [{$key}] is not an array.");
        }

        return $line;
    }

    /**
     * The line matching a count.
     *
     * Plural forms are separated by a pipe and chosen by the locale's own
     * rule — see {@see MessageSelector}. A form may instead carry an explicit
     * range, which wins: '{0} none|[1,19] some|[20,*] many'.
     *
     * The count may be given as the collection being counted, so a caller can
     * pass what it has rather than counting first.
     *
     * @param \Countable|array<mixed>|int|float $number
     * @param array<string, mixed>              $replace
     */
    public function choice(string $key, mixed $number, array $replace = [], ?string $locale = null): string
    {
        $locale = $this->localeForChoice($key, $locale);

        $line = $this->get($key, [], $locale);

        if (is_countable($number)) {
            $number = count($number);
        }

        $replace['count'] ??= $number;

        // A key naming a whole group has no form to choose between, so it is
        // treated the same as one with no line at all.
        return (string) $this->makeReplacements(
            $this->getSelector()->choose(is_string($line) ? $line : $key, $number, $locale),
            $replace
        );
    }

    /**
     * Which locale's plural rule to apply.
     *
     * The rule has to match the language the line is actually written in. A
     * Russian line falling back to English would otherwise be split by
     * Russian's three-form rule and pick a form that is not there.
     */
    protected function localeForChoice(string $key, ?string $locale): string
    {
        $locale ??= $this->locale;

        return $this->hasForLocale($key, $locale) ? $locale : $this->fallback;
    }

    /**
     * Add lines at runtime, without a file.
     *
     * Keys are dotted and name their group: ['messages.welcome' => 'Hi'].
     *
     * @param array<string, mixed> $lines
     */
    public function addLines(array $lines, string $locale, string $namespace = '*'): static
    {
        foreach ($lines as $key => $value) {
            [$group, $item] = array_pad(explode('.', (string) $key, 2), 2, null);

            if ($item === null) {
                continue;
            }

            $target = &$this->loaded[$namespace][$group][$locale];
            $target ??= [];

            $this->put($target, $item, $value);

            unset($target);
        }

        return $this;
    }

    /** Register a package's translation directory under a namespace. */
    public function addNamespace(string $namespace, string $hint): static
    {
        $this->loader->addNamespace($namespace, $hint);

        return $this;
    }

    /** @return array<string, string> */
    public function namespaces(): array
    {
        return $this->loader->namespaces();
    }

    /**
     * Add a directory to search for group files.
     *
     * @throws \BadMethodCallException when the lines do not come from disk.
     */
    public function addPath(string $path): static
    {
        if (! $this->loader instanceof FileLoader) {
            throw new \BadMethodCallException(
                'Loader [' . $this->loader::class . '] does not read translations from a directory.'
            );
        }

        $this->loader->addPath($path);

        return $this;
    }

    /** Add a directory to search for JSON files. */
    public function addJsonPath(string $path): static
    {
        $this->loader->addJsonPath($path);

        return $this;
    }

    /**
     * Read a group into memory, unless it is already there.
     */
    public function load(string $namespace, string $group, string $locale): void
    {
        if (isset($this->loaded[$namespace][$group][$locale])) {
            return;
        }

        $this->loaded[$namespace][$group][$locale] = $this->loader->load($locale, $group, $namespace);
    }

    /**
     * Split 'package::group.item' into its three parts.
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    public function parseKey(string $key): array
    {
        if (isset($this->parsed[$key])) {
            return $this->parsed[$key];
        }

        $namespace = '*';
        $remainder = $key;

        if (str_contains($remainder, '::')) {
            [$namespace, $remainder] = explode('::', $remainder, 2);
        }

        [$group, $item] = array_pad(explode('.', $remainder, 2), 2, null);

        return $this->parsed[$key] = [$namespace, $group, $item];
    }

    /**
     * Pin what a key splits into.
     *
     * For a key whose shape the framework would read wrongly — one whose group
     * name contains a dot, say.
     *
     * @param array{0: string, 1: string, 2: ?string} $parsed
     */
    public function setParsedKey(string $key, array $parsed): static
    {
        $this->parsed[$key] = $parsed;

        return $this;
    }

    /** Forget every split key, for a worker that reuses the instance. */
    public function flushParsedKeys(): static
    {
        $this->parsed = [];

        return $this;
    }

    /**
     * Which locales to try, nearest first.
     *
     * @return array<int, string>
     */
    protected function localesToTry(?string $locale): array
    {
        if ($this->localeResolver !== null) {
            return array_values(array_filter((array) ($this->localeResolver)($locale ?? $this->locale)));
        }

        return array_values(array_filter(array_unique([$locale ?? $this->locale, $this->fallback])));
    }

    /**
     * Decide the locale chain yourself.
     *
     * For an application whose locales have a hierarchy the framework cannot
     * guess — pt_BR before pt before en, say.
     *
     * @param (callable(string): array<int, string>)|null $callback
     */
    public function determineLocalesUsing(?callable $callback): static
    {
        $this->localeResolver = $callback === null ? null : Closure::fromCallable($callback);

        return $this;
    }

    /**
     * What to do with a key that has no line in any locale.
     *
     * Returning a string from the handler uses it as the line; returning
     * nothing leaves the key showing, which is the default.
     *
     * @param (callable(string, array<string, mixed>, string, bool): ?string)|null $callback
     */
    public function handleMissingKeysUsing(?callable $callback): static
    {
        $this->missingKeyHandler = $callback === null ? null : Closure::fromCallable($callback);

        return $this;
    }

    /** @param array<string, mixed> $replace */
    protected function handleMissingKey(string $key, array $replace, ?string $locale, bool $fallback): string
    {
        if ($this->missingKeyHandler === null || ! $this->handleMissingKeys) {
            return $key;
        }

        // The handler is very likely to translate something itself — an
        // exception message, a log line — so it must not re-enter this path.
        $this->handleMissingKeys = false;

        try {
            $result = ($this->missingKeyHandler)($key, $replace, $locale, $fallback);
        } finally {
            $this->handleMissingKeys = true;
        }

        return is_string($result) ? $result : $key;
    }

    /** How to render an object passed as a replacement value. */
    public function stringable(string $class, ?callable $handler = null): static
    {
        $this->stringableHandlers[$class] = $handler;

        return $this;
    }

    public function getSelector(): MessageSelector
    {
        return $this->selector ??= new MessageSelector();
    }

    public function setSelector(MessageSelector $selector): static
    {
        $this->selector = $selector;

        return $this;
    }

    public function getLoader(): Loader
    {
        return $this->loader;
    }

    /**
     * Replace everything already read, for a worker that reuses the instance.
     *
     * @param array<string, array<string, array<string, array<string, mixed>>>> $loaded
     */
    public function setLoaded(array $loaded): static
    {
        $this->loaded = $loaded;

        return $this;
    }

    /**
     * Find a line in one group of one locale.
     *
     * @param array<string, mixed> $replace
     * @return string|array<string, mixed>|null
     */
    protected function lineFrom(string $namespace, string $group, string $locale, ?string $item, array $replace): string|array|null
    {
        $this->load($namespace, $group, $locale);

        $lines = $this->loaded[$namespace][$group][$locale] ?? [];

        // No item means the key named the group itself, which is how the
        // validation messages are read: trans('validation') is the whole file.
        $line = $item === null ? $lines : $this->dig($lines, $item);

        if (is_string($line)) {
            return $this->makeReplacements($line, $replace);
        }

        if (is_array($line) && $line !== []) {
            array_walk_recursive($line, function (&$value) use ($replace): void {
                if (is_string($value)) {
                    $value = $this->makeReplacements($value, $replace);
                }
            });

            return $line;
        }

        return null;
    }

    /** Read a dotted path out of a nested array. */
    protected function dig(array $lines, string $key): mixed
    {
        if (array_key_exists($key, $lines)) {
            return $lines[$key];
        }

        foreach (explode('.', $key) as $segment) {
            if (! is_array($lines) || ! array_key_exists($segment, $lines)) {
                return null;
            }

            $lines = $lines[$segment];
        }

        return $lines;
    }

    /** Write a dotted path into a nested array. */
    protected function put(array &$lines, string $key, mixed $value): void
    {
        $segments = explode('.', $key);

        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                break;
            }

            if (! isset($lines[$segment]) || ! is_array($lines[$segment])) {
                $lines[$segment] = [];
            }

            $lines = &$lines[$segment];
        }

        $lines[end($segments)] = $value;
    }

    /**
     * Substitute :placeholders, matching the case of the placeholder.
     *
     * Every placeholder is replaced in one pass, so a value that happens to
     * contain a placeholder is not substituted again, and :name_full is not
     * eaten by a :name that was also given.
     *
     * A closure replaces a <tag>…</tag> pair instead, which is how a line
     * wraps part of itself in markup it should not have to know about.
     *
     * @param string|array<string, mixed> $line
     * @param array<string, mixed>        $replace
     * @return string|array<string, mixed>
     */
    protected function makeReplacements(string|array $line, array $replace): string|array
    {
        if ($replace === [] || is_array($line)) {
            return $line;
        }

        $substitutions = [];

        foreach ($replace as $key => $value) {
            if ($value instanceof Closure) {
                $line = (string) preg_replace_callback(
                    '/<' . preg_quote((string) $key, '/') . '>(.*?)<\/' . preg_quote((string) $key, '/') . '>/s',
                    static fn (array $matched): string => (string) $value($matched[1]),
                    $line
                );

                continue;
            }

            $value = (string) $this->stringify($value);

            $substitutions[':' . $this->ucfirst((string) $key)] = $this->ucfirst($value);
            $substitutions[':' . mb_strtoupper((string) $key, 'UTF-8')] = mb_strtoupper($value, 'UTF-8');
            $substitutions[':' . $key] = $value;
        }

        // strtr takes the longest matching placeholder at each position, which
        // str_replace in a loop does not.
        return $substitutions === [] ? $line : strtr($line, $substitutions);
    }

    /** An object given as a replacement, rendered. */
    protected function stringify(mixed $value): mixed
    {
        if (! is_object($value)) {
            return $value;
        }

        $handler = $this->stringableHandlers[$value::class] ?? null;

        if ($handler !== null) {
            return $handler($value);
        }

        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \UnitEnum => $value->name,
            default => (string) $value,
        };
    }

    private function ucfirst(string $value): string
    {
        return mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($value, 1, null, 'UTF-8');
    }
}
