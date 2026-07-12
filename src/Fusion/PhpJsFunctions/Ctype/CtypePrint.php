<?php

namespace Nitro\Fusion\PhpJsFunctions\Ctype;

use Nitro\Fusion\JsTranspile\BaseFunction;

class CtypePrint extends BaseFunction
{
    public static string $name = 'ctype_print';

    public static function getUses(): array
    {
        return ['setlocale'];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'CtypePrint.js';
        return file_get_contents($jsToInclude);
    }
}
