<?php

namespace Nitro\Fusion\PhpJsFunctions\Strings;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Quotemeta extends BaseFunction
{
    public static string $name = 'quotemeta';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Quotemeta.js';
        return file_get_contents($jsToInclude);
    }
}
