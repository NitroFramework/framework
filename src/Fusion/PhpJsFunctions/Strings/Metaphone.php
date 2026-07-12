<?php

namespace Nitro\Fusion\PhpJsFunctions\Strings;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Metaphone extends BaseFunction
{
    public static string $name = 'metaphone';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Metaphone.js';
        return file_get_contents($jsToInclude);
    }
}
