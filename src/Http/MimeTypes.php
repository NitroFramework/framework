<?php

namespace Nitro\Http;

/**
 * MIME type to extension mapping.
 *
 * Covers the types that actually arrive through web forms. Not exhaustive —
 * callers fall back to the client-supplied extension when a type is unknown.
 */
class MimeTypes
{
    /** @var array<string, array<int, string>> */
    private const MAP = [
        // images
        'image/jpeg'                    => ['jpg', 'jpeg', 'jpe'],
        'image/png'                     => ['png'],
        'image/gif'                     => ['gif'],
        'image/webp'                    => ['webp'],
        'image/avif'                    => ['avif'],
        'image/bmp'                     => ['bmp'],
        'image/svg+xml'                 => ['svg'],
        'image/tiff'                    => ['tif', 'tiff'],
        'image/x-icon'                  => ['ico'],
        'image/vnd.microsoft.icon'      => ['ico'],
        'image/heic'                    => ['heic'],

        // documents
        'application/pdf'               => ['pdf'],
        'application/msword'            => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.ms-excel'      => ['xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => ['xlsx'],
        'application/vnd.ms-powerpoint' => ['ppt'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'application/rtf'               => ['rtf'],
        'application/vnd.oasis.opendocument.text'        => ['odt'],
        'application/vnd.oasis.opendocument.spreadsheet' => ['ods'],

        // text
        'text/plain'                    => ['txt', 'text', 'log'],
        'text/csv'                      => ['csv'],
        'text/html'                     => ['html', 'htm'],
        'text/css'                      => ['css'],
        'text/markdown'                 => ['md'],
        'text/xml'                      => ['xml'],
        'application/json'              => ['json'],
        'application/xml'               => ['xml'],

        // archives
        'application/zip'               => ['zip'],
        'application/gzip'              => ['gz'],
        'application/x-tar'             => ['tar'],
        'application/x-7z-compressed'   => ['7z'],
        'application/vnd.rar'           => ['rar'],

        // audio / video
        'audio/mpeg'                    => ['mp3'],
        'audio/ogg'                     => ['ogg'],
        'audio/wav'                     => ['wav'],
        'audio/webm'                    => ['weba'],
        'video/mp4'                     => ['mp4'],
        'video/mpeg'                    => ['mpeg'],
        'video/webm'                    => ['webm'],
        'video/quicktime'               => ['mov'],
        'video/x-msvideo'               => ['avi'],

        // fonts
        'font/woff'                     => ['woff'],
        'font/woff2'                    => ['woff2'],
        'font/ttf'                      => ['ttf'],
        'font/otf'                      => ['otf'],
    ];

    /** The canonical extension for a MIME type, or null when unknown. */
    public static function extension(string $mimeType): ?string
    {
        $mimeType = strtolower(trim(explode(';', $mimeType)[0]));

        return self::MAP[$mimeType][0] ?? null;
    }

    /**
     * Every extension a MIME type may carry.
     *
     * @return array<int, string>
     */
    public static function extensions(string $mimeType): array
    {
        $mimeType = strtolower(trim(explode(';', $mimeType)[0]));

        return self::MAP[$mimeType] ?? [];
    }

    /**
     * MIME types that an extension may legitimately carry.
     *
     * @return array<int, string>
     */
    public static function typesFor(string $extension): array
    {
        $extension = strtolower(ltrim($extension, '.'));
        $types = [];

        foreach (self::MAP as $type => $extensions) {
            if (in_array($extension, $extensions, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /** Whether a MIME type is an image. */
    public static function isImage(string $mimeType): bool
    {
        return str_starts_with(strtolower($mimeType), 'image/');
    }
}
