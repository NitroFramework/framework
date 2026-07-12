<?php

namespace Nitro\Fusion\PhpJsFunctions\Strings;

use Nitro\Fusion\JsTranspile\BaseFunction;

class NumberFormat extends BaseFunction
{
    public static string $name = 'number_format';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'NumberFormat.js';
        return file_get_contents($jsToInclude);
    }
}
