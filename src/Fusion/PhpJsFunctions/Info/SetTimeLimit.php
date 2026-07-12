<?php

namespace Nitro\Fusion\PhpJsFunctions\Info;

use Nitro\Fusion\JsTranspile\BaseFunction;

class SetTimeLimit extends BaseFunction
{
    public static string $name = 'set_time_limit';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'SetTimeLimit.js';
        return file_get_contents($jsToInclude);
    }
}
