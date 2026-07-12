<?php

namespace Nitro\Fusion\PhpJsFunctions\Strings;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Strcasecmp extends BaseFunction
{
    public static string $name = 'strcasecmp';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Strcasecmp.js';
        return file_get_contents($jsToInclude);
    }
}
