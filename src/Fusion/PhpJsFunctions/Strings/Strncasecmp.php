<?php

namespace Nitro\Fusion\PhpJsFunctions\Strings;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Strncasecmp extends BaseFunction
{
    public static string $name = 'strncasecmp';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Strncasecmp.js';
        return file_get_contents($jsToInclude);
    }
}
