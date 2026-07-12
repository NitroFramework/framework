<?php

namespace Nitro\Fusion\PhpJsFunctions\Info;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Getenv extends BaseFunction
{
    public static string $name = 'getenv';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Getenv.js';
        return file_get_contents($jsToInclude);
    }
}
