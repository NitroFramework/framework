<?php

namespace Nitro\Fusion\PhpJsFunctions\Helpers;

use Nitro\Fusion\JsTranspile\BaseFunction;

class PhpCastFloat extends BaseFunction
{
    public static string $name = '_php_cast_float';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'PhpCastFloat.js';
        return file_get_contents($jsToInclude);
    }
}
