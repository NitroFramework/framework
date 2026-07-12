<?php

namespace Nitro\Fusion\PhpJsFunctions\Datetime;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Idate extends BaseFunction
{
    public static string $name = 'idate';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Idate.js';
        return file_get_contents($jsToInclude);
    }
}
