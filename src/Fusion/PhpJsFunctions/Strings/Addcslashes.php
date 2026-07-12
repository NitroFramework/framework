<?php

namespace Nitro\Fusion\PhpJsFunctions\Strings;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Addcslashes extends BaseFunction
{
    public static string $name = 'addcslashes';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Addcslashes.js';
        return file_get_contents($jsToInclude);
    }
}
