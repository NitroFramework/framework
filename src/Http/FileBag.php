<?php

namespace Nitro\Http;

/**
 * Turns $_FILES into a tree of {@see UploadedFile} objects.
 *
 * PHP pivots array file inputs the wrong way round: `<input name="docs[]">`
 * arrives as
 *
 *     ['docs' => ['name' => ['a.pdf','b.pdf'], 'tmp_name' => [...], ...]]
 *
 * — keyed by property first and index second. Nested names invert further, so
 * `photos[cover]` puts the key under each property. This rotates the structure
 * back so callers get `['docs' => [UploadedFile, UploadedFile]]`.
 */
class FileBag
{
    private const KEYS = ['name', 'type', 'tmp_name', 'error', 'size'];

    /**
     * @param  array<string, mixed> $files Raw $_FILES.
     * @return array<string, mixed> Tree of UploadedFile instances.
     */
    public static function normalize(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $normalized[$key] = self::convert($value);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $spec One $_FILES entry.
     */
    private static function convert(array $spec): mixed
    {
        // A leaf: the five upload keys with scalar values.
        if (self::isLeaf($spec)) {
            return self::make($spec);
        }

        // A pivoted group: the five keys, each holding an array.
        if (self::isPivoted($spec)) {
            return self::unpivot($spec);
        }

        // Already a plain nested array of entries.
        $out = [];

        foreach ($spec as $key => $value) {
            $out[$key] = is_array($value) ? self::convert($value) : $value;
        }

        return $out;
    }

    private static function isLeaf(array $spec): bool
    {
        return isset($spec['tmp_name']) && ! is_array($spec['tmp_name']);
    }

    private static function isPivoted(array $spec): bool
    {
        return isset($spec['tmp_name']) && is_array($spec['tmp_name']);
    }

    /**
     * Rotate ['name' => [...], 'tmp_name' => [...]] into per-index entries.
     */
    private static function unpivot(array $spec): array
    {
        $out = [];

        foreach (array_keys($spec['tmp_name']) as $index) {
            $entry = [];

            foreach (self::KEYS as $key) {
                $entry[$key] = $spec[$key][$index] ?? null;
            }

            $out[$index] = self::convert($entry);
        }

        return $out;
    }

    /** @param array<string, mixed> $spec */
    private static function make(array $spec): ?UploadedFile
    {
        $error = (int) ($spec['error'] ?? UPLOAD_ERR_NO_FILE);

        // An empty file input posts UPLOAD_ERR_NO_FILE. Reporting that as an
        // UploadedFile would make hasFile() true for a field nobody filled in.
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return new UploadedFile(
            (string) ($spec['tmp_name'] ?? ''),
            (string) ($spec['name'] ?? ''),
            isset($spec['type']) ? (string) $spec['type'] : null,
            $error,
            false,
            isset($spec['size']) ? (int) $spec['size'] : null
        );
    }
}
