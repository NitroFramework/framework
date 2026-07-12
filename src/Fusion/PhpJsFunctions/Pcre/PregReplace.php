<?php

namespace Nitro\Fusion\PhpJsFunctions\Pcre;

use Nitro\Fusion\JsTranspile\BaseFunction;

class PregReplace extends BaseFunction
{
    public static string $name = 'preg_replace';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'PregReplace.js';
        return file_get_contents($jsToInclude);
    }
}
