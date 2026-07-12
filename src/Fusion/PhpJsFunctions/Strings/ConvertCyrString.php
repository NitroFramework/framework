<?php

namespace Nitro\Fusion\PhpJsFunctions\Strings;

use Nitro\Fusion\JsTranspile\BaseFunction;

class ConvertCyrString extends BaseFunction
{
    public static string $name = 'convert_cyr_string';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'ConvertCyrString.js';
        return file_get_contents($jsToInclude);
    }
}
