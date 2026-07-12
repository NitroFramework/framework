<?php

namespace Nitro\Fusion\PhpJsFunctions\Math;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Round extends BaseFunction
{
    public static string $name = 'round';

    public static function getUses(): array
    {
        return ['_php_cast_float', '_php_cast_int'];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Round.js';
        return file_get_contents($jsToInclude);
    }
}
