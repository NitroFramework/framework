<?php

namespace Nitro\Fusion\PhpJsFunctions\Url;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Base64Encode extends BaseFunction
{
    public static string $name = 'base64_encode';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Base64Encode.js';
        return file_get_contents($jsToInclude);
    }
}
