<?php

namespace Nitro\Fusion\PhpJsFunctions\Helpers;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Bc extends BaseFunction
{
    public static string $name = '_bc';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Bc.js';
        return file_get_contents($jsToInclude);
    }
}
