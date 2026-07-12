<?php

namespace Nitro\Fusion\PhpJsFunctions\Url;

use Nitro\Fusion\JsTranspile\BaseFunction;

class ParseUrl extends BaseFunction
{
    public static string $name = 'parse_url';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'ParseUrl.js';
        return file_get_contents($jsToInclude);
    }
}
