<?php

namespace Nitro\Fusion\PhpJsFunctions\Array;

use Nitro\Fusion\JsTranspile\BaseFunction;

class ArrayPad extends BaseFunction
{
    public static string $name = 'array_pad';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'ArrayPad.js';
        return file_get_contents($jsToInclude);
    }
}
