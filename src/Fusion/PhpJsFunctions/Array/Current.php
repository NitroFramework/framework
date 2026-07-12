<?php

namespace Nitro\Fusion\PhpJsFunctions\Array;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Current extends BaseFunction
{
    public static string $name = 'current';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Current.js';
        return file_get_contents($jsToInclude);
    }
}
