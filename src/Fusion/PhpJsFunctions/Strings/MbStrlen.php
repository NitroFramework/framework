<?php

namespace Nitro\Fusion\PhpJsFunctions\Strings;

use Nitro\Fusion\JsTranspile\BaseFunction;

class MbStrlen extends BaseFunction
{
    public static string $name = 'mb_strlen';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'MbStrlen.js';
        return file_get_contents($jsToInclude);
    }
}
