<?php

namespace Nitro\Fusion\PhpJsFunctions\I18n;

use Nitro\Fusion\JsTranspile\BaseFunction;

class I18nLocSetDefault extends BaseFunction
{
    public static string $name = 'i18n_loc_set_default';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'I18nLocSetDefault.js';
        return file_get_contents($jsToInclude);
    }
}
