<?php

namespace Nitro\Fusion\PhpJsFunctions\Array;

use Nitro\Fusion\JsTranspile\BaseFunction;

class ArrayUnshift extends BaseFunction
{
    public static string $name = 'array_unshift';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'ArrayUnshift.js';
        return file_get_contents($jsToInclude);
    }
}
