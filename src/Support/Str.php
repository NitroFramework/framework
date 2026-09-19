<?php

namespace Nitro\Support;

/**
 * String helpers — Laravel's `Str::` surface (common subset).
 *
 *   Str::slug('Hello World');   // hello-world
 *   Str::studly('blog_post');   // BlogPost
 *   Str::limit($text, 100);
 */
class Str
{
    /**
     * Lowercase transliteration of common Latin accented characters to ASCII,
     * used by slug()/ascii(). Not exhaustive like Laravel's full table (which
     * ships a large per-language map) but covers the everyday Western-European
     * letters; unmapped scripts pass through unchanged rather than vanishing.
     */
    private const ASCII_MAP = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a', 'æ' => 'ae',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ĉ' => 'c', 'ċ' => 'c',
        'ð' => 'd', 'ď' => 'd', 'đ' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ę' => 'e', 'ě' => 'e', 'ė' => 'e',
        'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g',
        'ĥ' => 'h', 'ħ' => 'h',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i',
        'ĵ' => 'j', 'ķ' => 'k',
        'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ł' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o', 'œ' => 'oe',
        'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r',
        'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's', 'ș' => 's', 'ß' => 'ss',
        'ţ' => 't', 'ť' => 't', 'ŧ' => 't', 'ț' => 't', 'þ' => 'th',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ŵ' => 'w',
        'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y',
        'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
    ];

    /**
     * Transliterate common accented Latin letters to ASCII (lowercasing first).
     * `Str::ascii('Café')` → `cafe`. Unmapped characters are left as-is.
     */
    public static function ascii(string $value): string
    {
        return strtr(mb_strtolower($value, 'UTF-8'), self::ASCII_MAP);
    }

    public static function contains(string $haystack, string|array $needles, bool $ignoreCase = false): bool
    {
        if ($ignoreCase) {
            $haystack = mb_strtolower($haystack);
        }

        foreach ((array) $needles as $needle) {
            $needle = (string) $needle;

            if ($ignoreCase) {
                $needle = mb_strtolower($needle);
            }

            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    public static function startsWith(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_starts_with($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    public static function endsWith(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_ends_with($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    public static function length(string $value): int
    {
        return mb_strlen($value);
    }

    public static function lower(string $value): string
    {
        return mb_strtolower($value);
    }

    public static function upper(string $value): string
    {
        return mb_strtoupper($value);
    }

    public static function title(string $value): string
    {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    public static function ucfirst(string $value): string
    {
        return mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1);
    }

    public static function limit(string $value, int $limit = 100, string $end = '...'): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }
        return rtrim(mb_substr($value, 0, $limit)) . $end;
    }

    public static function slug(string $title, string $separator = '-'): string
    {
        $quoted = preg_quote($separator, '~');

        // Fold the "other" separator into the chosen one.
        $flip = $separator === '-' ? '_' : '-';
        $title = preg_replace('~[' . preg_quote($flip, '~') . ']+~u', $separator, $title);

        // Transliterate common accented letters to ASCII (café → cafe), like
        // Laravel — this also lowercases. Then drop anything that isn't a
        // letter, number, the separator, or whitespace. (The old non-\u
        // [^-\w] regex stripped every accented letter and ignored $separator.)
        $title = static::ascii($title);
        $title = preg_replace('~[^' . $quoted . '\pL\pN\s]+~u', '', $title);

        // Collapse runs of whitespace/separators into a single separator.
        $title = preg_replace('~[' . $quoted . '\s]+~u', $separator, $title);

        return trim($title ?? '', $separator);
    }

    public static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    public static function camel(string $value): string
    {
        return lcfirst(static::studly($value));
    }

    public static function snake(string $value, string $delimiter = '_'): string
    {
        if (ctype_lower($value)) {
            return $value;
        }
        $value = preg_replace('/\s+/u', '', ucwords($value));
        $value = preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $value);
        return mb_strtolower($value ?? '');
    }

    public static function kebab(string $value): string
    {
        return static::snake($value, '-');
    }

    public static function replace(string|array $search, string|array $replace, string $subject): string
    {
        return str_replace($search, $replace, $subject);
    }

    public static function after(string $subject, string $search): string
    {
        return $search === '' ? $subject : array_reverse(explode($search, $subject, 2))[0];
    }

    public static function before(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }
        $result = strstr($subject, $search, true);
        return $result === false ? $subject : $result;
    }

    public static function finish(string $value, string $cap): string
    {
        return preg_replace('/(?:' . preg_quote($cap, '/') . ')+$/u', '', $value) . $cap;
    }

    public static function start(string $value, string $prefix): string
    {
        return $prefix . preg_replace('/^(?:' . preg_quote($prefix, '/') . ')+/u', '', $value);
    }

    public static function random(int $length = 16): string
    {
        // Base62-ish alphabet (a-zA-Z0-9) like Laravel, ~5.95 bits/char — the
        // old hex output was [0-9a-f], roughly half the entropy per character.
        $string = '';
        while (($len = strlen($string)) < $length) {
            $size  = $length - $len;
            $bytes = random_bytes((int) ceil($size / 3) * 3);
            $string .= substr(str_replace(['/', '+', '='], '', base64_encode($bytes)), 0, $size);
        }
        return $string;
    }

    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value,
        );
    }

    public static function words(string $value, int $words = 100, string $end = '...'): string
    {
        preg_match('/^\s*+(?:\S++\s*+){1,' . $words . '}/u', $value, $matches);
        if (!isset($matches[0]) || mb_strlen($value) === mb_strlen($matches[0])) {
            return $value;
        }
        return rtrim($matches[0]) . $end;
    }

    /**
     * Words whose plural is not built by rule.
     *
     * @var array<string, string>
     */
    private const IRREGULAR_PLURALS = [
        'person' => 'people',
        'man' => 'men',
        'woman' => 'women',
        'child' => 'children',
        'tooth' => 'teeth',
        'foot' => 'feet',
        'mouse' => 'mice',
        'goose' => 'geese',
        'criterion' => 'criteria',
        'datum' => 'data',
        'medium' => 'media',
        'analysis' => 'analyses',
        'diagnosis' => 'diagnoses',
        'thesis' => 'theses',
        'matrix' => 'matrices',
        'quiz' => 'quizzes',
    ];

    /** Words that are the same in the plural. */
    private const UNCOUNTABLE = [
        'equipment', 'information', 'staff', 'series', 'species',
        'sheep', 'fish', 'news', 'audio', 'training', 'feedback',
    ];

    /**
     * The plural of an English word.
     *
     * Deliberately rule-based rather than exhaustive. It exists because the
     * naive "add an s" it replaces turns category into categorys and company
     * into companys — which surfaces as a missing-table error at insert time,
     * a long way from the migration that caused it.
     *
     * Where a name is genuinely irregular and not listed here, name the table
     * explicitly: that is always available and always unambiguous.
     */
    public static function plural(string $value, int|float|array|\Countable $count = 2): string
    {
        // A count of exactly one keeps the singular, so a template can write
        // Str::plural('course', $n) and get "1 course" / "2 courses" without
        // an inline conditional at every call site.
        if (is_array($count) || $count instanceof \Countable) {
            $count = count($count);
        }

        if ((float) $count === 1.0) {
            return $value;
        }

        $lower = mb_strtolower($value);

        if (in_array($lower, self::UNCOUNTABLE, true)) {
            return $value;
        }

        if (isset(self::IRREGULAR_PLURALS[$lower])) {
            return self::matchCase($value, self::IRREGULAR_PLURALS[$lower]);
        }

        // Consonant + y → ies ('category' → 'categories'); vowel + y stays
        // ('day' → 'days').
        if (preg_match('/[^aeiou]y$/i', $value)) {
            return self::matchCase($value, substr($value, 0, -1) . 'ies');
        }

        // Sibilant endings take -es, or the plural is unpronounceable.
        if (preg_match('/(s|x|z|ch|sh)$/i', $value)) {
            return self::matchCase($value, $value . 'es');
        }

        // 'shelf' → 'shelves', 'knife' → 'knives'.
        if (preg_match('/(?:[^f]fe|[lr]f)$/i', $value)) {
            return self::matchCase($value, preg_replace('/(?:([^f])fe|([lr])f)$/i', '$1$2ves', $value));
        }

        return self::matchCase($value, $value . 's');
    }

    /**
     * The singular of an English word — the inverse of {@see plural()}.
     *
     * Rule-based for the same reason and with the same escape hatch. It exists
     * because the obvious rtrim($word, 's') strips every trailing s at once:
     * address becomes addre, status becomes statu, and a resource route ends
     * up with a parameter nobody typed.
     *
     * The rules below run in order, each undoing one of plural()'s:
     *
     *   uncountable   series, news            unchanged
     *   irregular     people → person         from the table
     *   -ies          categories → category   but not series, caught above
     *   -lves/-rves   shelves → shelf         knives → knife
     *   sibilant -es  boxes → box             the only case taking off two
     *   -ss -us -is   address, status, basis  already singular, left alone
     *   trailing -s   users → user            everything else
     *
     * The second-to-last rule is the one that matters: -ss, -us and -is are all
     * singular endings and no English plural uses them, so a word wearing one
     * must survive untouched.
     */
    public static function singular(string $value): string
    {
        $lower = mb_strtolower($value);

        if (in_array($lower, self::UNCOUNTABLE, true)) {
            return $value;
        }

        $irregular = array_search($lower, self::IRREGULAR_PLURALS, true);

        if ($irregular !== false) {
            return self::matchCase($value, $irregular);
        }

        if (preg_match('/[^aeiou]ies$/i', $value)) {
            return self::matchCase($value, substr($value, 0, -3) . 'y');
        }

        if (preg_match('/([lr])ves$/i', $value)) {
            return self::matchCase($value, substr($value, 0, -3) . 'f');
        }

        if (preg_match('/([^f])ves$/i', $value)) {
            return self::matchCase($value, substr($value, 0, -3) . 'fe');
        }

        if (preg_match('/(ss|s|x|z|ch|sh)es$/i', $value)) {
            return self::matchCase($value, substr($value, 0, -2));
        }

        if (preg_match('/(ss|us|is)$/i', $value)) {
            return $value;
        }

        if (str_ends_with($lower, 's')) {
            return self::matchCase($value, substr($value, 0, -1));
        }

        return $value;
    }

    /**
     * Give the plural the capitalisation of the original, so Category
     * pluralises to Categories rather than to categories.
     */
    private static function matchCase(string $original, string $plural): string
    {
        if ($original === mb_strtoupper($original)) {
            return mb_strtoupper($plural);
        }

        if ($original !== '' && mb_substr($original, 0, 1) === mb_strtoupper(mb_substr($original, 0, 1))) {
            return static::ucfirst($plural);
        }

        return $plural;
    }

    /** Begin a fluent chain over $value. */
    public static function of(string $value): Stringable
    {
        return new Stringable($value);
    }

    // ─── Slicing ──────────────────────────────────────────────────────────

    /** Everything after the last occurrence of $search. */
    public static function afterLast(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $position = strrpos($subject, $search);

        return $position === false ? $subject : substr($subject, $position + strlen($search));
    }

    /** Everything before the last occurrence of $search. */
    public static function beforeLast(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $position = strrpos($subject, $search);

        return $position === false ? $subject : substr($subject, 0, $position);
    }

    /** The text between the first $from and the last $to. */
    public static function between(string $subject, string $from, string $to): string
    {
        if ($from === '' || $to === '') {
            return $subject;
        }

        return static::beforeLast(static::after($subject, $from), $to);
    }

    /** The text between the first $from and the first $to after it. */
    public static function betweenFirst(string $subject, string $from, string $to): string
    {
        if ($from === '' || $to === '') {
            return $subject;
        }

        return static::before(static::after($subject, $from), $to);
    }

    /** The character at $index, counting from the end when negative. */
    public static function charAt(string $subject, int $index): string|false
    {
        $length = mb_strlen($subject);

        if ($index < 0) {
            $index += $length;
        }

        if ($index < 0 || $index >= $length) {
            return false;
        }

        return mb_substr($subject, $index, 1);
    }

    public static function substr(string $subject, int $start, ?int $length = null): string
    {
        return mb_substr($subject, $start, $length);
    }

    /** The first $limit characters. */
    public static function take(string $subject, int $limit): string
    {
        return $limit < 0
            ? mb_substr($subject, $limit)
            : mb_substr($subject, 0, $limit);
    }

    /** Remove $needle from the start, if it is there. */
    public static function chopStart(string $subject, string|array $needle): string
    {
        foreach ((array) $needle as $prefix) {
            if ($prefix !== '' && str_starts_with($subject, (string) $prefix)) {
                return substr($subject, strlen((string) $prefix));
            }
        }

        return $subject;
    }

    /** Remove $needle from the end, if it is there. */
    public static function chopEnd(string $subject, string|array $needle): string
    {
        foreach ((array) $needle as $suffix) {
            if ($suffix !== '' && str_ends_with($subject, (string) $suffix)) {
                return substr($subject, 0, -strlen((string) $suffix));
            }
        }

        return $subject;
    }

    // ─── Searching ────────────────────────────────────────────────────────

    /** Whether the subject contains every one of $needles. */
    public static function containsAll(string $haystack, array $needles, bool $ignoreCase = false): bool
    {
        foreach ($needles as $needle) {
            if (! static::contains($haystack, (string) $needle, $ignoreCase)) {
                return false;
            }
        }

        return true;
    }

    public static function doesntContain(string $haystack, string|array $needles, bool $ignoreCase = false): bool
    {
        foreach ((array) $needles as $needle) {
            if (static::contains($haystack, (string) $needle, $ignoreCase)) {
                return false;
            }
        }

        return true;
    }

    public static function doesntStartWith(string $haystack, string|array $needles): bool
    {
        return ! static::startsWith($haystack, $needles);
    }

    public static function doesntEndWith(string $haystack, string|array $needles): bool
    {
        return ! static::endsWith($haystack, $needles);
    }

    /** The byte position of the first $needle, or false. */
    public static function position(string $haystack, string $needle, int $offset = 0): int|false
    {
        return mb_strpos($haystack, $needle, $offset);
    }

    public static function substrCount(string $haystack, string $needle, int $offset = 0, ?int $length = null): int
    {
        return $length === null
            ? substr_count($haystack, $needle, $offset)
            : substr_count($haystack, $needle, $offset, $length);
    }

    /**
     * Whether the subject matches a pattern where `*` stands for any run of
     * characters. A literal match always wins first.
     */
    public static function is(string|array $pattern, string $value): bool
    {
        foreach ((array) $pattern as $candidate) {
            $candidate = (string) $candidate;

            if ($candidate === $value) {
                return true;
            }

            if (! str_contains($candidate, '*')) {
                continue;
            }

            $regex = str_replace('\*', '.*', preg_quote($candidate, '#'));

            if (preg_match('#^' . $regex . '\z#u', $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Whether the subject matches a regular expression. */
    public static function isMatch(string|array $pattern, string $value): bool
    {
        foreach ((array) $pattern as $candidate) {
            if (preg_match((string) $candidate, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /** The first capture group of $pattern, or an empty string. */
    public static function match(string $pattern, string $subject): string
    {
        if (preg_match($pattern, $subject, $matches) !== 1) {
            return '';
        }

        return $matches[1] ?? $matches[0];
    }

    /**
     * Every match of $pattern.
     *
     * @return array<int, string>
     */
    public static function matchAll(string $pattern, string $subject): array
    {
        if (preg_match_all($pattern, $subject, $matches) === false) {
            return [];
        }

        return $matches[1] ?? $matches[0];
    }

    // ─── Predicates ───────────────────────────────────────────────────────

    public static function isAscii(string $value): bool
    {
        return preg_match('/^[\x00-\x7F]*$/', $value) === 1;
    }

    public static function isJson(string $value): bool
    {
        if (trim($value) === '') {
            return false;
        }

        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }

    public static function isUlid(string $value): bool
    {
        return preg_match('/^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$/', $value) === 1;
    }

    public static function isUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    // ─── Case ─────────────────────────────────────────────────────────────

    public static function lcfirst(string $value): string
    {
        return mb_strtolower(mb_substr($value, 0, 1)) . mb_substr($value, 1);
    }

    /** StudlyCase, the same shape as studly(). */
    public static function pascal(string $value): string
    {
        return static::studly($value);
    }

    public static function pluralStudly(string $value, int $count = 2): string
    {
        return $count === 1 ? static::studly($value) : static::studly(static::plural($value));
    }

    public static function pluralPascal(string $value, int $count = 2): string
    {
        return static::pluralStudly($value, $count);
    }

    /** Capitalise each word, splitting on whitespace only. */
    public static function ucwords(string $value, string $delimiters = " \t\r\n\f\v"): string
    {
        return ucwords($value, $delimiters);
    }

    /**
     * Split a StudlyCase string into its words.
     *
     * @return array<int, string>
     */
    public static function ucsplit(string $value): array
    {
        return preg_split('/(?=\p{Lu})/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Words separated by single spaces, each capitalised. */
    public static function headline(string $value): string
    {
        $parts = preg_split('/[\s_-]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $words = [];

        foreach ($parts as $part) {
            foreach (static::ucsplit($part) ?: [$part] as $word) {
                $words[] = static::ucfirst($word);
            }
        }

        return implode(' ', $words);
    }

    /** Change case with one of the MB_CASE_* modes. */
    public static function convertCase(string $value, int $mode = MB_CASE_FOLD, string $encoding = 'UTF-8'): string
    {
        return mb_convert_case($value, $mode, $encoding);
    }

    /**
     * The initials of each word, upper-cased.
     */
    public static function initials(string $value, string $separator = ''): string
    {
        $words = preg_split('/[\s_-]+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $initials = array_map(
            static fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)),
            $words
        );

        return implode($separator, $initials);
    }

    // ─── Rewriting ────────────────────────────────────────────────────────

    public static function replaceFirst(string $search, string $replace, string $subject): string
    {
        if ($search === '') {
            return $subject;
        }

        $position = strpos($subject, $search);

        return $position === false
            ? $subject
            : substr_replace($subject, $replace, $position, strlen($search));
    }

    public static function replaceLast(string $search, string $replace, string $subject): string
    {
        if ($search === '') {
            return $subject;
        }

        $position = strrpos($subject, $search);

        return $position === false
            ? $subject
            : substr_replace($subject, $replace, $position, strlen($search));
    }

    /** Replace $search only where it begins the subject. */
    public static function replaceStart(string $search, string $replace, string $subject): string
    {
        return ($search !== '' && str_starts_with($subject, $search))
            ? static::replaceFirst($search, $replace, $subject)
            : $subject;
    }

    /** Replace $search only where it ends the subject. */
    public static function replaceEnd(string $search, string $replace, string $subject): string
    {
        return ($search !== '' && str_ends_with($subject, $search))
            ? static::replaceLast($search, $replace, $subject)
            : $subject;
    }

    /**
     * Replace each occurrence of $search with the next value from $replace.
     *
     * @param array<int, string> $replace
     */
    public static function replaceArray(string $search, array $replace, string $subject): string
    {
        foreach ($replace as $value) {
            $subject = static::replaceFirst($search, (string) $value, $subject);
        }

        return $subject;
    }

    /** Replace everything matching a regular expression. */
    public static function replaceMatches(string $pattern, string|callable $replace, string $subject, int $limit = -1): string
    {
        if (is_callable($replace)) {
            return preg_replace_callback($pattern, $replace, $subject, $limit) ?? $subject;
        }

        return preg_replace($pattern, $replace, $subject, $limit) ?? $subject;
    }

    /** Remove every occurrence of $search. */
    public static function remove(string|array $search, string $subject, bool $caseSensitive = true): string
    {
        return $caseSensitive
            ? str_replace($search, '', $subject)
            : str_ireplace($search, '', $subject);
    }

    /**
     * Swap several substrings at once.
     *
     * @param array<string, string> $map
     */
    public static function swap(array $map, string $subject): string
    {
        return strtr($subject, $map);
    }

    public static function substrReplace(string $subject, string $replace, int $offset = 0, ?int $length = null): string
    {
        return $length === null
            ? substr_replace($subject, $replace, $offset)
            : substr_replace($subject, $replace, $offset, $length);
    }

    public static function reverse(string $value): string
    {
        return implode('', array_reverse(mb_str_split($value)));
    }

    public static function repeat(string $value, int $times): string
    {
        return $times > 0 ? str_repeat($value, $times) : '';
    }

    /** Collapse runs of whitespace into single spaces and trim. */
    public static function squish(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /** Collapse runs of $character into one. */
    public static function deduplicate(string $value, string $character = ' '): string
    {
        return preg_replace('/' . preg_quote($character, '/') . '+/u', $character, $value) ?? $value;
    }

    public static function trim(string $value, ?string $characters = null): string
    {
        return $characters === null ? trim($value) : trim($value, $characters);
    }

    public static function ltrim(string $value, ?string $characters = null): string
    {
        return $characters === null ? ltrim($value) : ltrim($value, $characters);
    }

    public static function rtrim(string $value, ?string $characters = null): string
    {
        return $characters === null ? rtrim($value) : rtrim($value, $characters);
    }

    /** Surround the value with $before and $after. */
    public static function wrap(string $value, string $before, ?string $after = null): string
    {
        return $before . $value . ($after ?? $before);
    }

    /** Remove a surrounding pair, if both sides are present. */
    public static function unwrap(string $value, string $before, ?string $after = null): string
    {
        $after ??= $before;

        if (str_starts_with($value, $before)) {
            $value = substr($value, strlen($before));
        }

        if (str_ends_with($value, $after)) {
            $value = substr($value, 0, -strlen($after));
        }

        return $value;
    }

    // ─── Padding ──────────────────────────────────────────────────────────

    public static function padLeft(string $value, int $length, string $pad = ' '): string
    {
        return static::pad($value, $length, $pad, STR_PAD_LEFT);
    }

    public static function padRight(string $value, int $length, string $pad = ' '): string
    {
        return static::pad($value, $length, $pad, STR_PAD_RIGHT);
    }

    public static function padBoth(string $value, int $length, string $pad = ' '): string
    {
        return static::pad($value, $length, $pad, STR_PAD_BOTH);
    }

    /**
     * Pad by character count rather than byte count, so a multi-byte string
     * pads to the width it is displayed at.
     */
    private static function pad(string $value, int $length, string $pad, int $type): string
    {
        $short = max(0, $length - mb_strlen($value));

        return match ($type) {
            STR_PAD_LEFT  => mb_substr(str_repeat($pad, $short), 0, $short) . $value,
            STR_PAD_RIGHT => $value . mb_substr(str_repeat($pad, $short), 0, $short),
            default       => mb_substr(str_repeat($pad, (int) floor($short / 2)), 0, (int) floor($short / 2))
                . $value
                . mb_substr(str_repeat($pad, (int) ceil($short / 2)), 0, (int) ceil($short / 2)),
        };
    }

    // ─── Words ────────────────────────────────────────────────────────────

    public static function wordCount(string $value): int
    {
        return count(preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** Break long lines at word boundaries. */
    public static function wordWrap(string $value, int $characters = 75, string $break = "\n", bool $cutLongWords = false): string
    {
        return wordwrap($value, $characters, $break, $cutLongWords);
    }

    /**
     * A short extract around the first occurrence of a phrase.
     *
     * @param array{radius?: int, omission?: string} $options
     */
    public static function excerpt(string $text, string $phrase = '', array $options = []): ?string
    {
        $radius = $options['radius'] ?? 100;
        $omission = $options['omission'] ?? '...';

        $position = $phrase === '' ? 0 : mb_stripos($text, $phrase);

        if ($position === false) {
            return null;
        }

        $start = max(0, $position - $radius);
        $length = mb_strlen($phrase) + ($radius * 2) + ($position - $start > 0 ? 0 : 0);

        $extract = mb_substr($text, $start, ($position - $start) + mb_strlen($phrase) + $radius);

        return ($start > 0 ? $omission : '')
            . $extract
            . ($start + mb_strlen($extract) < mb_strlen($text) ? $omission : '');
    }

    /** Only the digits in the value. */
    public static function numbers(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }

    // ─── Masking and encoding ─────────────────────────────────────────────

    /**
     * Replace part of the value with a repeated character.
     *
     * A negative $index counts from the end, so an email can be masked as
     * mask($email, '*', 2) without knowing its length.
     */
    public static function mask(string $value, string $character, int $index, ?int $length = null): string
    {
        if ($character === '') {
            return $value;
        }

        $segment = mb_substr($value, $index, $length);

        if ($segment === '') {
            return $value;
        }

        $start = $index < 0 ? max(0, mb_strlen($value) + $index) : $index;

        return mb_substr($value, 0, $start)
            . str_repeat(mb_substr($character, 0, 1), mb_strlen($segment))
            . mb_substr($value, $start + mb_strlen($segment));
    }

    public static function toBase64(string $value): string
    {
        return base64_encode($value);
    }

    public static function fromBase64(string $value, bool $strict = false): string|false
    {
        return base64_decode($value, $strict);
    }

    // ─── Identifiers ──────────────────────────────────────────────────────

    /**
     * A time-ordered UUID (version 7).
     *
     * Sorts by creation time, which keeps a database index appending rather
     * than fragmenting the way a random v4 does.
     */
    public static function uuid7(?\DateTimeInterface $time = null): string
    {
        $milliseconds = $time === null
            ? (int) (microtime(true) * 1000)
            : (int) ($time->format('U.u') * 1000);

        $bytes = random_bytes(16);

        // 48-bit big-endian timestamp, then the version and variant bits.
        for ($i = 5; $i >= 0; $i--) {
            $bytes[$i] = chr($milliseconds & 0xFF);
            $milliseconds >>= 8;
        }

        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** Alias of {@see uuid7()}, for callers that want ordering by name. */
    public static function orderedUuid(): string
    {
        return static::uuid7();
    }

    /** A ULID: 48 bits of timestamp then 80 bits of randomness, base32. */
    public static function ulid(?\DateTimeInterface $time = null): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

        $milliseconds = $time === null
            ? (int) (microtime(true) * 1000)
            : (int) ($time->format('U.u') * 1000);

        $timePart = '';
        for ($i = 9; $i >= 0; $i--) {
            $timePart = $alphabet[$milliseconds % 32] . $timePart;
            $milliseconds = intdiv($milliseconds, 32);
        }

        $randomPart = '';
        for ($i = 0; $i < 16; $i++) {
            $randomPart .= $alphabet[random_int(0, 31)];
        }

        return $timePart . $randomPart;
    }

    /**
     * A random password of mixed character classes.
     */
    public static function password(int $length = 32, bool $letters = true, bool $numbers = true, bool $symbols = true, bool $spaces = false): string
    {
        $pool = [];

        if ($letters) {
            $pool = array_merge($pool, str_split('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'));
        }

        if ($numbers) {
            $pool = array_merge($pool, str_split('0123456789'));
        }

        if ($symbols) {
            $pool = array_merge($pool, str_split('~!#$%^&*()-_.,<>?/\\{}[]|:;'));
        }

        if ($spaces) {
            $pool[] = ' ';
        }

        if ($pool === []) {
            return '';
        }

        $password = '';
        $max = count($pool) - 1;

        for ($i = 0; $i < $length; $i++) {
            $password .= $pool[random_int(0, $max)];
        }

        return $password;
    }

    /**
     * Split a "Class@method" callback string.
     *
     * @return array{0: string, 1: string|null}
     */
    public static function parseCallback(string $callback, ?string $default = null): array
    {
        if (! str_contains($callback, '@')) {
            return [$callback, $default];
        }

        [$class, $method] = explode('@', $callback, 2);

        return [$class, $method];
    }
}
