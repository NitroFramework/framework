<?php

namespace Nitro\Fusion\PhpJsFunctions\Filesystem;

use Nitro\Fusion\JsTranspile\BaseFunction;

class Dirname extends BaseFunction
{
    public static string $name = 'dirname';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'Dirname.js';
        return file_get_contents($jsToInclude);
    }
}
