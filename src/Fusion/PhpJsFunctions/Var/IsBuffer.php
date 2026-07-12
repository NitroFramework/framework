<?php

namespace Nitro\Fusion\PhpJsFunctions\Var;

use Nitro\Fusion\JsTranspile\BaseFunction;

class IsBuffer extends BaseFunction
{
    public static string $name = 'is_buffer';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'IsBuffer.js';
        return file_get_contents($jsToInclude);
    }
}
