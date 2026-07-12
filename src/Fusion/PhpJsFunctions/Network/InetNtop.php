<?php

namespace Nitro\Fusion\PhpJsFunctions\Network;

use Nitro\Fusion\JsTranspile\BaseFunction;

class InetNtop extends BaseFunction
{
    public static string $name = 'inet_ntop';

    public static function getUses(): array
    {
        return [];
    }

    public static function getJs(): string
    {
        $jsToInclude = __DIR__ . DIRECTORY_SEPARATOR . 'InetNtop.js';
        return file_get_contents($jsToInclude);
    }
}
