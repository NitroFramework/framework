<?php

namespace Nitro\Fusion\PhpJsFunctions\Array;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Usort extends BaseFunction
{
    public static string $name = 'usort';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Usort.js';
        return file_get_contents($jsToInclude);
    }
}
