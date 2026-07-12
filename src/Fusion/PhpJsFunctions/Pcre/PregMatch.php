<?php

namespace Nitro\Fusion\PhpJsFunctions\Pcre;

use Nitro\Fusion\JsTranspile\BaseFunction;

class PregMatch extends BaseFunction
{
    public static string $name = 'preg_match';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'PregMatch.js';
        return file_get_contents($jsToInclude);
    }
}
