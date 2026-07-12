<?php

namespace Nitro\Fusion\PhpJsFunctions\Datetime;

use Nitro\Fusion\JsTranspile\BaseFunction;

class DateParse extends BaseFunction
{
    public static string $name = 'date_parse';

    public static function getUses(): array
    {
        return ['strtotime'];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'DateParse.js';
        return file_get_contents($jsToInclude);
    }
}
