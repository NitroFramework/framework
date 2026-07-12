<?php

namespace Nitro\Fusion\PhpJsFunctions\Strings;

use Nitro\Fusion\JsTranspile\BaseFunction;

class StrIreplace extends BaseFunction
{
    public static string $name = 'str_ireplace';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'StrIreplace.js';
        return file_get_contents($jsToInclude);
    }
}
