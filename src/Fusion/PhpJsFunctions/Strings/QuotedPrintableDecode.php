<?php

namespace Nitro\Fusion\PhpJsFunctions\Strings;

use Nitro\Fusion\JsTranspile\BaseFunction;

class QuotedPrintableDecode extends BaseFunction
{
    public static string $name = 'quoted_printable_decode';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'QuotedPrintableDecode.js';
        return file_get_contents($jsToInclude);
    }
}
