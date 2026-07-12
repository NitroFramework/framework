<?php

namespace Nitro\Fusion\PhpJsFunctions\Math;

use Nitro\Fusion\JsTranspile\BaseFunction;

class IsInfinite extends BaseFunction
{
    public static string $name = 'is_infinite';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'IsInfinite.js';
        return file_get_contents($jsToInclude);
    }
}
