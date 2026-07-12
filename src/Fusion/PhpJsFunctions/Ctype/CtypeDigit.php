<?php

namespace Nitro\Fusion\PhpJsFunctions\Ctype;

use Nitro\Fusion\JsTranspile\BaseFunction;

class CtypeDigit extends BaseFunction
{
    public static string $name = 'ctype_digit';

    public static function getUses(): array
    {
        return ['setlocale'];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'CtypeDigit.js';
        return file_get_contents($jsToInclude);
    }
}
