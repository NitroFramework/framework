<?php

namespace Nitro\Fusion\PhpJsFunctions\Strings;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Strspn extends BaseFunction
{
    public static string $name = 'strspn';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Strspn.js';
        return file_get_contents($jsToInclude);
    }
}
