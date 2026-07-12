<?php

namespace Nitro\Fusion\PhpJsFunctions\Bc;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Bcsub extends BaseFunction
{
    public static string $name = 'bcsub';

    public static function getUses(): array
    {
        return ['_bc'];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Bcsub.js';
        return file_get_contents($jsToInclude);
    }
}
