<?php

namespace Nitro\Fusion\PhpJsFunctions\Strings;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Vprintf extends BaseFunction
{
    public static string $name = 'vprintf';

    public static function getUses(): array
    {
        return ['sprintf', 'echo'];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Vprintf.js';
        return file_get_contents($jsToInclude);
    }
}
