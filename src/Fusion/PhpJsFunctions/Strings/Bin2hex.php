<?php

namespace Nitro\Fusion\PhpJsFunctions\Strings;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Bin2hex extends BaseFunction
{
    public static string $name = 'bin2hex';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Bin2hex.js';
        return file_get_contents($jsToInclude);
    }
}
