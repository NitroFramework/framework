<?php

namespace Nitro\Fusion\PhpJsFunctions\Strings;

use Nitro\Fusion\JsTranspile\BaseFunction;

class GetHtmlTranslationTable extends BaseFunction
{
    public static string $name = 'get_html_translation_table';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'GetHtmlTranslationTable.js';
        return file_get_contents($jsToInclude);
    }
}
