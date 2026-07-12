<?php

namespace Nitro\Fusion\PhpJsFunctions\Bc;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Bccomp extends BaseFunction
{
    public static string $name = 'bccomp';

    public static function getUses(): array
    {
        return ['_bc'];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Bccomp.js';
        return file_get_contents($jsToInclude);
    }
}
