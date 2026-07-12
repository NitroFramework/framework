<?php

namespace Nitro\Fusion\PhpJsFunctions\Misc;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Uniqid extends BaseFunction
{
    public static string $name = 'uniqid';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Uniqid.js';
        return file_get_contents($jsToInclude);
    }
}
