<?php

namespace Nitro\Fusion\PhpJsFunctions\Math;

use Nitro\Fusion\JsTranspile\BaseFunction;

class LcgValue extends BaseFunction
{
    public static string $name = 'lcg_value';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'LcgValue.js';
        return file_get_contents($jsToInclude);
    }
}
