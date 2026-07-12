<?php

namespace Nitro\Fusion\PhpJsFunctions\Network;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Ip2long extends BaseFunction
{
    public static string $name = 'ip2long';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Ip2long.js';
        return file_get_contents($jsToInclude);
    }
}
