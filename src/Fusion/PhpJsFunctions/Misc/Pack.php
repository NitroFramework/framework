<?php

namespace Nitro\Fusion\PhpJsFunctions\Misc;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Pack extends BaseFunction
{
    public static string $name = 'pack';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Pack.js';
        return file_get_contents($jsToInclude);
    }
}
