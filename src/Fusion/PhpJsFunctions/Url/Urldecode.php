<?php

namespace Nitro\Fusion\PhpJsFunctions\Url;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Urldecode extends BaseFunction
{
    public static string $name = 'urldecode';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Urldecode.js';
        return file_get_contents($jsToInclude);
    }
}
