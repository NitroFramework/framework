<?php

namespace Nitro\Http\Testing;

use Nitro\Http\MimeTypes;

/**
 * The MIME type a fake file reports, worked out from its name.
 */
class MimeType
{
    /** The type for a filename's extension. */
    public static function from(string $filename): string
    {
        return self::get(pathinfo($filename, PATHINFO_EXTENSION));
    }

    /** The first type an extension may carry, or application/octet-stream. */
    public static function get(string $extension): string
    {
        return MimeTypes::typesFor($extension)[0] ?? 'application/octet-stream';
    }

    /** The extension for a type, or null when unknown. */
    public static function search(string $mimeType): ?string
    {
        return MimeTypes::extension($mimeType);
    }
}
