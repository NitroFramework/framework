<?php

namespace Nitro\Fusion\PhpJsFunctions\Bc;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Bcround extends BaseFunction
{
    public static string $name = 'bcround';

    public static function getUses(): array
    {
        return ['_bc'];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Bcround.js';
        return file_get_contents($jsToInclude);
    }
}
