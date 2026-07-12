<?php

namespace Nitro\Fusion\PhpJsFunctions\Math;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Acos extends BaseFunction
{
    public static string $name = 'acos';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Acos.js';
        return file_get_contents($jsToInclude);
    }
}
