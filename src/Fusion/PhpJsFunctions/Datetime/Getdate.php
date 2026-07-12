<?php

namespace Nitro\Fusion\PhpJsFunctions\Datetime;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Getdate extends BaseFunction
{
    public static string $name = 'getdate';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Getdate.js';
        return file_get_contents($jsToInclude);
    }
}
