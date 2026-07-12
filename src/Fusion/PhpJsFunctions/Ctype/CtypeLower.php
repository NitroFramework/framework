<?php

namespace Nitro\Fusion\PhpJsFunctions\Ctype;

use Nitro\Fusion\JsTranspile\BaseFunction;

class CtypeLower extends BaseFunction
{
    public static string $name = 'ctype_lower';

    public static function getUses(): array
    {
        return ['setlocale'];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'CtypeLower.js';
        return file_get_contents($jsToInclude);
    }
}
