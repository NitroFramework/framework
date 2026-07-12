<?php

namespace Nitro\Fusion\PhpJsFunctions\Strings;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Setlocale extends BaseFunction
{
    public static string $name = 'setlocale';

    public static function getUses(): array
    {
        return ['getenv'];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Setlocale.js';
        return file_get_contents($jsToInclude);
    }
}
