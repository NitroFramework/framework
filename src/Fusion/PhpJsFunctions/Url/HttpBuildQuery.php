<?php

namespace Nitro\Fusion\PhpJsFunctions\Url;

use Nitro\Fusion\JsTranspile\BaseFunction;

class HttpBuildQuery extends BaseFunction
{
    public static string $name = 'http_build_query';

    public static function getUses(): array
    {
        return ['rawurlencode', 'urlencode'];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'HttpBuildQuery.js';
        return file_get_contents($jsToInclude);
    }
}
