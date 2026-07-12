<?php

namespace Nitro\Fusion\PhpJsFunctions\Array;

use Nitro\Fusion\JsTranspile\BaseFunction;

class ArrayDiffKey extends BaseFunction
{
    public static string $name = 'array_diff_key';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'ArrayDiffKey.js';
        return file_get_contents($jsToInclude);
    }
}
