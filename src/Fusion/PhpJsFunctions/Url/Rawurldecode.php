<?php

namespace Nitro\Fusion\PhpJsFunctions\Url;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Rawurldecode extends BaseFunction
{
    public static string $name = 'rawurldecode';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Rawurldecode.js';
        return file_get_contents($jsToInclude);
    }
}
