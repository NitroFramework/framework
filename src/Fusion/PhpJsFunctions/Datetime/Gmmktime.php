<?php

namespace Nitro\Fusion\PhpJsFunctions\Datetime;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Gmmktime extends BaseFunction
{
    public static string $name = 'gmmktime';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Gmmktime.js';
        return file_get_contents($jsToInclude);
    }
}
