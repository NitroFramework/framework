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

    public static function contains(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
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
}
