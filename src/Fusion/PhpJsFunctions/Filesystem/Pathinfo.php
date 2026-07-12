<?php

namespace Nitro\Fusion\PhpJsFunctions\Filesystem;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Pathinfo extends BaseFunction
{
    public static string $name = 'pathinfo';

    public static function getUses(): array
    {
        return ['basename'];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Pathinfo.js';
        return file_get_contents($jsToInclude);
    }
}
